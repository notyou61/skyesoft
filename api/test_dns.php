<?php

echo "DNS RESULT: " . gethostbyname("api.kalshi.com");

curl_setopt($ch, CURLOPT_RESOLVE, [
    "api.kalshi.com:443:34.120.XXX.XXX"
]);