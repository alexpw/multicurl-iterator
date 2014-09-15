Multicurl Iterator
==================
This iterator class makes it simple to use PHP's multicurl effectively.

However, it does not curl_init() for you.  Instead, it gives you full control over what requests you'll be making.

## Install

Composer/Packagist
 - https://packagist.org/packages/alexpw/multicurl-iterator

## Basic usage
1. You instantiate a Multicurl\Iterator instance.
2. You give it some curl resource handles from curl_init().
3. You start foreach'ing over the results.
```php
$mci = new Alexpw\Multicurl\Iterator();

foreach ($curl_handles as $handle) {
	$mci->add($handle /*[, mixed $data = null] */);
}

foreach ($mci as $result) {
	doSomething($result);
}
```
It executes as many curl requests in parallel as you allow it.  As soon as the first response is received, the foreach is allowed to continue, and you are given the ```$result``` of parsing the response.

### The contents of $result
An approximation:
```php
$result = curl_getinfo($ch);
$result['handle']   = $ch;
$result['data']     = // Optional data associated with curl handle
$result['header']   = $header_string_or_parsed_array;
$result['body']     = $body_string;
$result['errno']    = curl_errno($ch);
$result['error']    = curl_error($ch);
$result['errorstr'] = curl_errstr($ch); // when function_exists
```
### $data, or How to ID a Request
The results are returned out of order and as soon as they are ready, but you can ID them easily.

The most convenient:
```$mci->add($handle, $data = null);```

$data can be anything that's meaningful to you:

 - a unique id
 - a copy of the request parameters
 - whatever

## Options
 - How many curl requests to execute simultaneously.

```$mci->setMaxExecuting(10); // default: 10 ```

 - Parse the response header as an array (if your request asked for headers).
```$mci->setParseResponseHeader(true); // default: true```

 - Whether to automatically close curl handles after using them.

```$mci->setCloseCurlHandles(true); // default: true```

Note, if you want to be able to reuse curl handles for retries or whatever, you'll need to manage and close them yourself.

## Real world usage
You don't need to create all of your curl handles in advance, they can be added at any time.

1. Setup your data
2. You instantiate a Multicurl\Iterator instance.
3. You configure options.
4. You give it some curl resource handles from curl_init().
5. You start foreach'ing over the results.
```php
$article_ids       = array(1, 2, 3, 4 /*,...*/);
$article_id_chunks = array_chunk($article_ids, 50);

$curr_chunk   = 0;
$total_chunks = count($article_id_chunks);

function addArticlesToIterator($mci, $chunk)
{
	foreach ($chunk as $article_id) {
		$ch = initCurlHandleForArticle($article_id);
		$mci->add($ch, $article_id);
	}
}

$mci = new Alexpw\Multicurl\Iterator();
$mci->setMaxExecuting(6);

addArticlesToIterator($mci, $article_id_chunks[$curr_chunk++]);

foreach ($mci as $result) {
	if ($mci->getCountPendingRequests() < 10 &&
		$curr_chunk < $total_chunks) {
		addArticleRequests($mci, $article_id_chunks[$curr_chunk++]);
	}

	if ($result['errno'] !== 0) {
		logFailed($result);
	} elseif ($result['http_code'] === 408) {
		$ch = initCurlHandleForRetry($result);
		$mci->add($ch);
    } else {
		doSomething($result);
    }
}
```

## License

Multicurl Iterator is licensed under the MIT License - see the LICENSE file for details.
