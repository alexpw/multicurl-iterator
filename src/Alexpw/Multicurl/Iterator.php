<?php
/* vim: set shiftwidth=4 expandtab softtabstop=4: */

namespace Alexpw\Multicurl;

/**
 * @example
 * $mci = new Alexpw\Multicurl\Iterator;
 * $mci->add($handles);
 * foreach ($mci as $result) {
 *     echo ' => ' .$result['url'] . "\n";
 *     echo $result['body'];
 * }
 */
class Iterator implements \Iterator
{
    private $mc_handle;
    private $mc_count     = 0;
    private $max_execing  = 10;
    private $node_storage;
    private $request_queue;
    private $result_queue;
    private $parse_response_header = true;
    private $close_curl_handles = true;

    /**
     * Construct - inits a multi curl handle.
     */
    public function __construct()
    {
        $this->mc_handle    = curl_multi_init();

        $this->node_storage = new \SplObjectStorage;

        $this->request_queue   = new \SplQueue;
        $this->request_queue->setIteratorMode(\SplDoublyLinkedList::IT_MODE_DELETE);

        $this->result_queue = new \SplQueue;
        $this->result_queue->setIteratorMode(\SplDoublyLinkedList::IT_MODE_DELETE);
    }

    /**
     * Destruct - conditionally closes all open curl handles.
     * @return null
     */
    public function __destruct()
    {
        curl_multi_close($this->mc_handle);

        if ($this->close_curl_handles) {
            $this->destroyCurlQueue();
        }
    }

    /**
     * Destroy will close the curl resource queue
     * @return null
     */
    private function destroyCurlQueue()
    {
        foreach ($this->request_queue as $ch) {
            if (is_resource($ch)) {
                curl_close($ch);
            }
        }
    }

    /**
     * Set the maximum number of simultaneously executing curl requests.
     * @param int $max_execing
     */
    public function setMaxExecuting($max_execing)
    {
        $this->max_execing = max(1, $max_execing + 0);
    }

    /**
     * Whether to parse the response header string into an associative array.
     * @param bool $should_parse_response_header
     */
    public function setParseResponseHeader($should_parse_response_header)
    {
        $this->parse_response_header = !!$should_parse_response_header;
    }

    /**
     * Whether to close the curl handles, or leave that up to you.
     * @param bool $should_close_curl_handle
     */
    public function setCloseCurlHandles($should_close_curl_handle)
    {
        $this->close_curl_handles = !!$should_close_curl_handle;
    }

    /**
     * Add a curl resource handle to the queue to be processed, with optional
     * data associated with it.  The $result given to you during iteration will
     * contain $result['data'], which you can use to identify the request.
     *
     * @param resource $curl_handle A curl resource handle
     * @param mixed    $data        Optional data to associate with the handle.
     */
    public function add($curl_handle, $data = null)
    {
        $node = new CurlNode;
        $node->handle = $curl_handle;
        $node->data   = $data;
        $this->request_queue->enqueue($node);
    }

    /**
     * @return int The number of requests that have not started executing.
     */
    public function getCountPendingRequests()
    {
        return $this->request_queue->count();
    }

    /**
     * Iterator - rewind() - Expected to be called in the context of foreach.
     * This only allows a one-way traversal, like a NoRewindIterator.
     */
    public function rewind()
    {
        if (count($this->result_queue) === 0) {
            $this->transferQueueToMulticurl();
            $this->next();
        }
    }

    /**
     * Iterator - valid() - Expected to be called in the context of foreach.
     * We expect to have at least 1 finished request when we start traversing,
     * due to rewind() calling next().  We can rely on the result queue.
     * @return bool
     */
    public function valid()
    {
        return count($this->result_queue) !== 0;
    }

    /**
     * Iterator - current() - Expected to be called in the context of foreach.
     * See parseResponse() for the format.
     * @return array
     */
    public function current()
    {
        return $this->result_queue->dequeue();
    }

    /**
     * Iterator - key() - Expected to be called in the context of foreach.
     * Omitted, no known use case.
     */
    public function key()
    {
    }

    /**
     * Iterator - next() - Expected to be called in the context of foreach.
     * The work horse.
     * Execute the multi curl handle and return as soon as one is finished.
     */
    public function next()
    {
        if ($this->mc_count === 0) {
            return;
        }
        $execing = CURLM_CALL_MULTI_PERFORM;

        $running = 0;
        while (curl_multi_exec($this->mc_handle, $running) === $execing);

        if ($running > 0) {
            $was_running = $running;
            while ($was_running === $running) {
                curl_multi_select($this->mc_handle);
                while (curl_multi_exec($this->mc_handle, $running) === $execing);
            }
        }

        while ($msg = curl_multi_info_read($this->mc_handle)) {
            $ch     = $msg['handle'];
            $result = $this->parseResponse($ch);

            $this->result_queue->enqueue($result);

            curl_multi_remove_handle($this->mc_handle, $ch);
            $this->mc_count--;

            if ($this->close_curl_handles) {
                curl_close($ch);
            }
        }

        // At least 1 request finished, so load more.
        if ($this->transferQueueToMulticurl()) {
            // and start them
            while (curl_multi_exec($this->mc_handle, $running) === $execing);
        }
    }

    /**
     * Adds as many curl handles to the multicurl instance as possible, which
     * is determined by the maximum number allowed to execute simultaneously
     * and the number of curl handles remaining in the queue to be processed.
     *
     * @return bool Whether a curl handle was added to multi curl.
     */
    private function transferQueueToMulticurl()
    {
        $added = false;
        while ($this->mc_count < $this->max_execing &&
                count($this->request_queue) !== 0) {

            $node = $this->request_queue->dequeue();
            $this->node_storage->attach($node);

            if (is_resource($node->handle)) {
                $added = true;
                curl_multi_add_handle($this->mc_handle, $node->handle);
                $this->mc_count++;
            }
        }
        return $added;
    }

    private function findNodeDataAndDetach($ch)
    {
        foreach ($this->node_storage as $node) {
            if ($node->handle === $ch) {
                $this->node_storage->detach($node);
                return $node->data;
            }
        }
    }

    /**
     * Pull the response information out of the curl handle.
     * @param resource $ch Curl handle
     * @return array
     */
    protected function parseResponse($ch)
    {
        $result = curl_getinfo($ch);

        $result['handle'] = $ch;
        $result['data']   = $this->findNodeDataAndDetach($ch);

        if ($result['download_content_length'] > 0 ||
            $result['header_size'] > 0) {
            $content = curl_multi_getcontent($ch);

            if ($result['header_size'] > 0) {
                list($header, $body) = explode("\r\n\r\n", $content, 2);
                if ($this->parse_response_header) {
                    $result['header'] = $this->parseResponseHeader($header);
                } else {
                    $result['header'] = $header;
                }
                $result['body'] = $body;
            } else {
                $result['body'] = $content;
            }
        }

        $result['errno'] = curl_errno($ch);
        $result['error'] = curl_error($ch);
        if (function_exists('curl_strerror')) {
            $result['strerror'] = curl_strerror($result['errno']);
        }
        return $result;
    }

    /**
     * @param string $header
     * @return array
     */
    protected function parseResponseHeader($header)
    {
        $headers = array();
        foreach (explode("\r\n", $header) as $line) {
            if (($pos = strpos($line, ':')) !== false) {
                $key = substr($line, 0, $pos);
                $headers[$key] = substr($line, $pos + 2);
            }
        }
        return $headers;
    }
}
