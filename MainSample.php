<?php
require_once  'ADConnectSample.php';

echo "<h2>簡単なサンプルクラス メールの部分一致検索：</h2><br/>";
$adConnSample = new ADConnectSample();
$resultAttrs = array(AdConnectSample::$ATTR_MAIL, AdConnectSample::$ATTR_SAM_ACCOUNT_NAME, );
$result = $adConnSample->searchByPartialMatch($adConnSample::$ATTR_MAIL, '@gmail.com', $resultAttrs);
var_dump(count($result));
var_dump($result);