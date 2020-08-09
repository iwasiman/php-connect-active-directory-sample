<?php

// 接続先はグローバル変数で指定しているが適宜変える。
// {サーバ名:ポート} + {/か\} + {ベースDN設定} で指定。
// ポートは不要な場合は指定していても繋がる。
/** AD連携の接続先パス  */
$ACTIVE_DIRECTORY_SERVER_PATH = '11.22.33.44:5555/OU=SampleBaseDNDir,OU=moreDir,DC=domaincntrl,DC=local';
/** AD連携の接続ユーザ  */
$ACTIVE_DIRECTORY_SERVER_USER = 'domain\username';
/** AD連携の接続パスワード */
$ACTIVE_DIRECTORY_SERVER_PASSWORD = 'password';



/**
 * Active Directory 接続のサンプル。
 * AD連携を行い、必要な情報を取得します。
 *
 * @package    -
 * @author     sample
 */
class ADConnectSample
{
    // ADスキーマ上のアトリビュート名を表す定数群。適宜追加すること。
    /** 属性：メールアドレス */
    public static $ATTR_MAIL = 'mail';
    /** 属性：サムアカウントネーム */
    public static $ATTR_SAM_ACCOUNT_NAME = 'sAMAccountName';
    
    /** AD連携の接続先パス(サーバ名＋検索先のベースDN)  */
    private $activeDirectoryServerPath = null;
    /** AD連携の接続ユーザ  */
    private $activeDirectoryServerUser = null;
    /** AD連携の接続パスワード */
    private $activeDirectoryServerPassword = null;
    
    /** 接続先ADのホスト */
    private $adHost = null;
    /** 検索先ADのポート */
    private $adPort = null;
    /** 検索先ディレクトリのベースDN */
    private $baseDn = null;
    
    /**
     * コンストラクタ。
     */
    function __construct() {
        global $ACTIVE_DIRECTORY_SERVER_PATH;
        global $ACTIVE_DIRECTORY_SERVER_USER;
        global $ACTIVE_DIRECTORY_SERVER_PASSWORD;
        $this->activeDirectoryServerPath = $ACTIVE_DIRECTORY_SERVER_PATH;
        $this->activeDirectoryServerUser = $ACTIVE_DIRECTORY_SERVER_USER;
        $this->activeDirectoryServerPassword = $ACTIVE_DIRECTORY_SERVER_PASSWORD;
        $this->initialize();
    }
    
    /**
     * 初期化処理を行います。
     * 設定値から、内部で持つホスト、ポート、ベースDNを導き出します。
     */
    private function initialize() {
        $sep = null;
        if (strstr($this->activeDirectoryServerPath, '\\')) {
            $sep = '\\';
        } else if (strstr($this->activeDirectoryServerPath, '/')) {
            $sep = '/';
        } else {
            die('AD連携の接続先パスが不正です。設定値：' . $this->activeDirectoryServerPath);
        }
        $splittedPath = explode($sep, $this->activeDirectoryServerPath);
        if (count($splittedPath) < 2) {
            die('AD連携の接続先パスが不正です。設定値：' . $this->activeDirectoryServerPath);
        }
        $hostAndPort =  $splittedPath[0];
        $colon = ':';
        if (strstr($hostAndPort, $colon)) {
            $splitted = explode($colon, $hostAndPort);
            $this->adHost = $splitted[0];
            $this->adPort = $splitted[1];
        } else {
            $this->adHost = $hostAndPort;
            $this->adPort = null;
        }
        
        $this->baseDn = $splittedPath[1];
        
    }
    
    /**
     * ADに接続し、ユーザ名を指定したログインを行います。
     * 
     * @return resource LDAP接続情報(成功時:LDAPリンクID / 失敗時：falseだが、その前に例外で終了)
     */
    private function connenct() {
        // まずLDAPサーバへ接続できるかを確認する。
        // パラメ―タ確認のみで接続はしない。IPが違ったりする場合も成功して進み、ログインで失敗する。
        $ldapConn = ldap_connect('ldap://' . $this->adHost, $this->adPort)
            or die('Active Directoryと接続できません。接続先設定を再確認してください。接続先:' . $this->activeDirectoryServerPath);
        if ($ldapConn) {
            echo('ADと接続可能です。' . '<br/>');
        } else {
            die('Active Directoryと接続できません。接続先設定を再確認してください。接続先:' . $this->activeDirectoryServerPath);
        }
        
        // オプション設定
        // 必ず以下を指定しないと、Windowsではつながらない場合がある模様
        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3) // LDAP V3プロトコルを使用
            or die('プロトコルバージョンをセットできません。');
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0) // リフェラルの自動追跡を行う
            or die('リフェラルの自動追跡設定をセットできません。');
        
        // 次にバインド(ログイン)する
        // ユーザ/パスワードが違うと、ここで失敗する。
        $ldapBindResult = @ldap_bind($ldapConn, $this->activeDirectoryServerUser, $this->activeDirectoryServerPassword);
        if ($ldapBindResult) {
            echo('ADのログインに成功しました。接続情報：' . $ldapConn . '<br/>');
        } else {
            die('ADのログインに失敗しました。ユーザ名：' . $this->activeDirectoryServerUser);
        }
        
        // 接続先パスの後半、ベースDNが違うと、接続もログインもできるが探す場所が正しくないので検索結果0件になる。
        return $ldapConn;
    }
    
    
    /**
     * ADとの接続解除を行います。
     * 
     * @param resource $ldapConn LDAP接続情報
     */
    private function disConnect($ldapConn) {
        // LDAPディレクトリへのバインドを解除する
        $ldapUnbindResult = ldap_unbind($ldapConn);
        if ($ldapUnbindResult) {
            echo('AD接続の解除に成功しました。' . '<br/>');
        } else {
            die('AD接続の解除に失敗しました。');
        }
    }
    
    /**
     * 検索結果の１件を理解しやすい連想配列に変換します。
     * 
     * @param resource $ldapConn LDAP接続情報(LDAPリンクID)
     * @param object $entry 検索結果エントリ１件
     * @param array $attributes 取得したい属性を持った文字列の配列
     * @returns array [キー=>値] に属性=>属性の値 の形式で検索結果１件分の結果を持った連想配列
     *                (値が検索できなかった場合は、キー自体がない)
     */
    private function convEntryToAssocArray($ldapConn, $entry, $attributes) {
        
        $entryAttrs = ldap_get_attributes($ldapConn, $entry);
        $adInfo = array();
        for($i = 0; $i < $entryAttrs['count']; $i ++) {
            $entryAttrName = $entryAttrs[$i];
            $entryAttrValues = $entryAttrs[$entryAttrName];
            if (is_null($entryAttrValues)) {
                $adInfo[$entryAttrName] = null;
            } else {
                $adInfo[$entryAttrName] = $entryAttrValues[0];
            }
        }
        return $adInfo;
    }
    
    /**
     * 検索のメイン処理を行います。
     * 
     * @param string $filter 検索に使うLDAPフィルタ構文の文字列
     * @param array $attributes 検索結果に欲しい属性を並べた文字列配列
     * @return array １件ごとに[属性=>属性の値]の連想配列で保持した検索結果の配列
     *                (結果0件の場合は空の配列)
     */
    protected function doSearchProcess($filter, $attributes) {
        $ldapConn = $this->connenct();
        $result = ldap_search($ldapConn, $this->baseDn, $filter, $attributes);
        $resultCount = ldap_count_entries($ldapConn, $result);
        if (!$result) {
            $this->disConnect($ldapConn);
            die('検索に失敗しました');
        }
        
        echo ('検索に成功しました。結果：' . $result . '<br/>');
        echo('件数：' . $resultCount . '<br/>');
        
        // エントリ全体を取得すると、結果は全体のcountや各々のdnを含んだ多次元配列で非常に分かりづらいため、
        // １件ごとに分割し変換していく
        //$entries = ldap_get_entries($ldapConn, $result);
        
        $adInfoArray = array();
        if ($resultCount > 0) {
            $entry = ldap_first_entry($ldapConn, $result);
            do {
                $adInfoArray[] = $this->convEntryToAssocArray($ldapConn, $entry, $attributes);
            } while ($entry = ldap_next_entry($ldapConn, $entry));
        }
        
        $this->disConnect($ldapConn);
        return $adInfoArray;
    }
    
    // 以下、外部公開するAPI的なメソッド群 ----------------------------------------------
    /**
     * 属性を指定した完全一致検索を行います。
     * 
     * @param string $searchAttr 検索に使用する属性名(本クラスの定数から)
     * @param string $searchStr  検索文字列
     * @param array $attributes 検索結果に欲しい属性を並べた文字列配列
     * @return array １件ごとに[属性=>属性の値]の連想配列で保持した検索結果の配列
     *               (結果0件の場合は空の配列)
     */
    public function searchByExactMatch($searchAttr, $searchStr, $attributes) {
        $filter = '(' . $searchAttr . '=' . $searchStr . ')';
        return $this->doSearchProcess($filter, $attributes);
    }

    /**
     * 属性を指定した部分一致検索を行います。
     * 
     * @param string $searchAttr 検索に使用する属性名(本クラスの定数から)
     * @param string $searchStr  検索文字列
     * @param array $attributes 検索結果に欲しい属性を並べた文字列配列
     * @return array１件ごとに[属性=>属性の値]の連想配列で保持した検索結果の配列
     *               (結果0件の場合は空の配列)
     */
    public function searchByPartialMatch($searchAttr, $searchStr, $attributes) {
        $filter = '(' . $searchAttr . '=*' . $searchStr . '*)';
        return $this->doSearchProcess($filter, $attributes);
    }
}