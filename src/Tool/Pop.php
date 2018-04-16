<?php
/**
 * Tool_Popクラス
 *
 * POPサーバに接続してメールを取得するクラス。
 *
 * ex)
 * $pop = $this->getClass( 'Tool/Pop' );
 *
 * // 接続情報
 * $pop->host = 'mail.example.com';
 * $pop->user = 'mail_user';
 * $pop->pass = 'mail_pass';
 * $pop->host = '110';
 *
 * // 接続
 * $pop->connect();
 *
 * // メール取得
 * while( $pop->fetch() ) {
 *     // ヘッダー
 *     $header = $pop->getHeader();
 *     // メール本文
 *     $body = $pop->getBody();
 *     // 添付ファイル
 *     $attches = $pop->getAttachFile();
 *
 *     // メール削除
 *     $pop->delete();
 * }
 *
 * // 切断
 * $pop->close();
 *
 * @access public
 * @author lionheart.co.jp
 * @version 1.0.0
 */

class Tool_Pop
{
    protected
        $server = array(
            'HOST' => '',
            'USER' => '',
            'PASS' => '',
            'PORT' => '110',
        ),
        $info = array(
            'total' => 0,
            'size'  => 0,
        ),
        $position = 0,
        $br = "\r\n",
        $fp = NULL,
        $encoding = 'UTF-8',
        $header = array(),
        $body = NULL,
        $html = NULL,
        $boundary = NULL,
        $attached = array(),
        $attached_save_path = '',
        $_is_log = false,
        $_log_file = null;

    /**
     * ログファイルに出力
     *
     * @param string $message
     * @return void
     */
    protected function pushLog($message)
    {
        if($this->_is_log && ! empty($this->_log_file)) {
            file_put_contents( $this->_log_file, $message.PHP_EOL, FILE_APPEND );
        }
    }

    /**
     * コンストラクタ
     */
    public function __construct()
    {
        // Feggから呼ばれた時のみ添付ファイルの保存ディレクトリを初期設定する
        if( defined( 'FEGG_CODE_DIR' ) ) {
            $this->attached_save_path = FEGG_CODE_DIR.'/data/cache/mail_attached';
            if(! is_dir( $this->attached_save_path ) ) {
                mkdir( $this->attached_save_path, 0777, TRUE );
            }

            $this->_log_file = FEGG_CODE_DIR.'/data/cache/pop.log';
        }
    }

    /**
     * 添付ファイルの添付ディレクトリの指定
     *
     * @param string $dir
     * @return void
     */
    public function setAttachedSavePath( $dir )
    {
        if(! is_dir( $dir ) || ! is_writable( $dir ) ) {
            throw new Exception( 'Permission Error: "'.$dir.'" is Not Writable.' );
        }

        $this->attached_save_path = $dir;
    }

    /**
     * 改行コードの指定
     *
     * @param string $br
     * @return void
     */
    public function setBr( $br )
    {
        if(
            $br !== "\n" &&
            $br !== "\r" &&
            $br !== "\r\n"
        ) {
            throw new Exception( 'Not CR/LF.' );
        }

        $this->br = $br;
    }

    /**
     * デストラクタ
     */
    public function __destruct()
    {
        $this->close();
        $this->clean();
    }

    /**
     * POP接続
     * メール件数と総サイズも取得
     *
     * @return array( 'total' => 'メール件数', 'size' => '総サイズ' )
     */
    public function connect()
    {
        mb_internal_encoding( $this->encoding );
        mb_language( "Japanese" );

        $this->fp = fsockopen(
            $this->server[ 'HOST' ],
            $this->server[ 'PORT' ],
            $err,
            $errno
        );

        if(! $this->fp ) {
            throw new Exception( 'fsock Error' );
        }
        $this->_checkResponce();

        // ユーザ名を送信
        $this->_sendCommand( 'USER '.$this->server[ 'USER' ] );

        // パスワードを送信
        $this->_sendCommand( 'PASS '.$this->server[ 'PASS' ] );

        // メールボックスの状態を取得
        $buf = $this->_sendCommand( 'STAT' );
        sscanf( $buf, '+OK %d %d', $this->info['total'], $this->info['size'] );

        return $this->info;
    }

    /**
     * POP切断
     *
     * @return boolean
     */
    public function close()
    {
        // 繋がっていなければ終了
        if( $this->fp === NULL ) {
            return FALSE;
        }

        // 切断コマンドの送信
        $this->_sendCommand( 'QUIT' );
        fclose( $this->fp );

        // 変数の初期化
        $this->fp = NULL;
        return TRUE;
    }

    /**
     * テンポラリファイルを削除する
     * （現状は添付ファイルのみ）
     *
     * @return void
     */
    public function clean()
    {
        $this->removeDirectory( $this->attached_save_path, FALSE );
    }

    /**
     * ディレクトリ削除
     * via: Tool/File
     *
     * @param string  $directory
     * @param boolean $rmdir TRUEの時は指定ディレクトリを削除する（初期値）
     * @return void
     */
    protected function removeDirectory( $directory, $rmdir = TRUE )
    {
        if( is_dir( $directory ) ) {
            if( $handle = opendir( $directory ) ) {
                while( ( $item = readdir( $handle ) ) !== false) {

                    if( $item == "." || $item == ".." ) {
                        continue;
                    }

                    if( is_dir( $directory . "/" . $item ) ) {
                        // ディレクトリであれば自身を再帰呼出する
                        $this->removeDirectory( $directory . "/" . $item );
                    } else {
                        unlink( $directory . "/" . $item );
                    }
                }
                closedir( $handle );
            }

            if( $rmdir ) {
                rmdir( $directory );
            }
        }
    }

    /**
     * POP接続状況を確認
     *
     * @return array( 'total' => 'メール件数', 'size' => '総サイズ' )
     */
    public function getInfo()
    {
        return $this->info;
    }

    /**
     * メール受信
     *
     * @return boolean
     */
    public function fetch()
    {
        // カウントアップ
        $this->position ++;

        // メールは存在するか？
        if(
            $this->position > $this->info['total'] ||
            $this->position <= 0
        ) {
            return FALSE;
        }

        // 初期化
        $this->header = array();
        $this->body = NULL;
        $this->boundary = NULL;
        $this->attached = array();

        // メールを受信
        $line = $this->_sendCommand( 'RETR '.$this->position );
        $content = array();

        // read until '.'
        // while ( !preg_match( "/^\.$/", $line ) ) {
        while( $line !== '.' ) {
            $line = rtrim( fgets( $this->fp, 512 ) );
            $content[] = $line;
        }
        array_pop( $content );
        $this->analyzeContent( $content );

        return TRUE;
    }

    /**
     * メール本文を解析
     *
     * @return void
     */
    protected function analyzeContent( $content )
    {
        $_delete_lines = array();
        $_next_delete  = FALSE;
        $from = '';
        $to   = '';

        // ヘッダー情報を解析
        $this->analyzeHeader( $content );

        // 本文&添付ファイルを取得
        $this->analyzeBody( $content );
    }

    /**
     * ヘッダー情報を解析
     *
     * @param array &$content
     * @return void
     */
    protected function analyzeHeader( &$content )
    {
        $header_key = NULL;
        $header_val = array();

        foreach ( $content as $index => $value ) {
            unset( $content[ $index ] );

            if( preg_match( '/^([\w].*)?:\s(.*?)$/', $value, $matches ) ) {
                if( $header_key !== NULL ) {
                    $this->header[ $header_key ] = $header_val;
                }

                $header_key = strtoupper( trim( $matches[1] ) );
                $header_val = array( $this->decodingHeader( $matches[2] ) );
            } else {
                $value = trim( $value );

                if( strlen( $value ) <= 0 ) {
                    $this->header[ $header_key ] = $header_val;
                    break;
                }
                $header_val[] = $this->decodingHeader( $value );
            }
        }

        // 配列を整形
        $content = array_values($content);
    }

    /**
     * header内のテキストをデコーディング
     *
     * @param string $header_val
     * @return string
     */
    protected function decodingHeader( $header_val )
    {
        $header_val = trim( $header_val );
        if( preg_match( '/=\?(.*?)\?(.*)\?=/', $header_val, $matches ) ) {
            $header_val = mb_decode_mimeheader( $header_val );
        }
        return $header_val;
    }

    /**
     * 今取得しているメールのヘッダーを取得
     *
     * @param string $key
     * @param integer $index
     * @return mixed
     */
    public function getHeader( $key = NULL, $index = -1 )
    {
        if( $key === NULL ) {
            return $this->header;
        }

        if( isset( $this->header[ $key ] ) ) {
            if( $index >= 0 ) {
                if( isset( $this->header[ $key ][ $index ] ) ) {
                    return $this->header[ $key ][ $index ];
                }
            } else {
                return $this->header[ $key ];
            }
        }

        return FALSE;
    }

    /**
     * 本文・添付ファイル情報を解析
     *
     * @param array &$content
     * @return void
     */
    protected function analyzeBody( &$content )
    {
        $is_boundary = FALSE;

        // boundaryをチェック
        $content_types = $this->getHeader( 'CONTENT-TYPE' );
        if( $content_types !== FALSE ) {
            foreach( $content_types as $key => $content_type ) {
                if( preg_match( '/boundary=(.*)(\s|;|$)/', $content_type, $matches ) ) {
                    // 添付ファイルあり
                    $this->boundary = trim( str_replace( array( '"', "'", ';' ), '', $matches[1] ) );
                    $is_boundary = TRUE;
                }
            }
            $content_type = implode( '', $content_types );
        } else {
            $content_type = NULL;
        }

        // boundaryがあったか確認
        if( $is_boundary ) {
            // boundaryの処理を頑張る
            $this->analyzeBoundary( $this->boundary, $content );
        } else {
            // テキストメール
            $charset = 'ISO-2022-JP';
            if( preg_match( '/charset\=(.*)$/', $content_type, $matches ) ) {
                $charset = $matches[1];
            }

            $this->pushBody( $content, $this->getHeader() );
        }
    }

    /**
     * boundary内を解析
     *
     * @param string $boundary
     * @param array $content
     * @return void
     */
    protected function analyzeBoundary( $boundary, $content )
    {
        $body      = array();
        $is_header = FALSE;
        $header    = array();

        $header_key = NULL;
        $header_val = array();

        foreach( $content as $index => $value ) {
            if( '--' . $boundary . '--' == $value ) {
                // 最後のboundary
                $this->pushBody( $body, $header );
                break;
            } else if( '--' . $boundary == $value ) {
                // boundaryに一致
                if( count( $body ) && count( $header ) ) {
                    $this->pushBody( $body, $header );
                }

                // 初期化する
                $body      = array();
                $is_header = TRUE;
                $header    = array();
            } else {
                if( $is_header ) {
                    $value = trim( $value );

                    if( preg_match( '/^([\w].*)?:\s(.*?)$/', $value, $matches ) ) {
                        if( $header_key !== NULL ) {
                            $header[ $header_key ] = $header_val;

                            $header_key = NULL;
                            $header_val = array();
                        }

                        $header_key   = strtoupper( trim( $matches[1] ) );
                        $header_val[] = $this->decodingHeader( $matches[2] );
                    } else if( strlen( $value ) <= 0 ) {
                        if( $header_key !== NULL ) {
                            $header[ $header_key ] = $header_val;

                            $header_key = NULL;
                            $header_val = array();
                        }

                        $is_header = FALSE;
                    } else {
                        $header_val[] = $this->decodingHeader( $value );
                    }
                } else {
                    $body[] = $value;
                }
            }
        }
    }

    /**
     * boundaryで分割されている本文を判定、結合する
     *
     * @param $body
     * @param $header
     * @return void
     */
    protected function pushBody( $body, $header )
    {
        $content_type = isset( $header[ 'CONTENT-TYPE' ] ) ? implode( '', $header[ 'CONTENT-TYPE' ] ) : FALSE;
        $transfer = isset( $header[ 'CONTENT-TRANSFER-ENCODING' ] ) ? implode( '', $header[ 'CONTENT-TRANSFER-ENCODING' ] ) : FALSE;
        $transfer = strtolower($transfer);

        $this->pushLog($content_type);

        if( preg_match( '/text\/(plain|html);/', $content_type, $matches ) || $content_type === FALSE ) {
            // テキストの場合
            $body = implode( $this->br, $body );

            switch( $transfer ) {
                case 'base64':
                    $body = base64_decode( $body );
                    break;
                case 'quoted-printable':
                    $body = quoted_printable_decode( $body );
                    break;
                default:
                    $converted = base64_decode( $body );
                    if($converted) {
                        $body = $converted;
                    }
                    break;
            }

            // テキストメール
            $charset = 'ISO-2022-JP';
            if( preg_match( '/charset\=(.*?)(;|$)/', $content_type, $ch_matches ) ) {
                $charset = $ch_matches[1];
            }

            switch( $matches[1] ) {
                case 'plain':
                    $this->body .= mb_convert_encoding( $body, $this->encoding, $charset );
                    break;
                case 'html':
                    $this->html .= mb_convert_encoding( $body, $this->encoding, $charset );
                    break;
            }

        } else if( preg_match( '/multipart\/(.*?);/', $content_type, $matches ) ) {
            // HTMLメールとかデコメとか
            // boundaryを検出
            if( preg_match( '/boundary=(.*)/', $content_type, $bnd_matches ) ) {
                $boundary = str_replace( array( '"', "'" ), '', $bnd_matches[1] );

                // boundaryの処理を頑張る
                $this->analyzeBoundary( $boundary, $body );
            }
        } else {
            // 添付ファイル
            $this->attached[] = array(
                'header' => $header,
                'body'   => implode( $this->br, $body ),
            );
        }
    }

    /**
     * 今取得しているメールの本文を取得
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * 今取得しているメールのHTML本文を取得
     *
     * @return string
     */
    public function getHtml()
    {
        return $this->html;
    }

    /**
     * 添付ファイルを保存開始する
     * 保存したファイルパスを返す
     *
     * @param integer $index
     * @return mixed
     */
    public function getAttachFile( $index = NULL )
    {
        $result = NULL;

        if( $index === NULL ) {
            $result = array();
            foreach( $this->attached as $key => $attached ) {
                $result[] = $this->saveAttachFile( $attached );
            }
        } else {
            if( isset( $this->attached[ $index ] ) ) {
                $result = $this->saveAttachFile( $this->attached[ $index ] );
            }
        }

        return $result;
    }

    /**
     * 保存実行
     *
     * @param array $attached
     * @return string
     */
    protected function saveAttachFile( $attached )
    {
        $body = $attached['body'];

        // ファイル名の取得
        $filename = 'dummy.dat';
        $content_type = implode( '', $attached['header']['CONTENT-TYPE'] );
        if( preg_match( '/name=(.*?)(;|$)/', $content_type, $matches ) ) {
            $filename = str_replace( array( '"', "'" ), '', $matches[1] );
        }
        $filepath = $this->attached_save_path.'/'.$filename;

        // 変換形式
        $transfer = isset( $attached['header'][ 'CONTENT-TRANSFER-ENCODING' ] ) ? implode( ',', $attached['header'][ 'CONTENT-TRANSFER-ENCODING' ] ) : FALSE;
        switch( $transfer ) {
            case 'base64':
                $body = base64_decode( $body );
                break;
            case 'quoted-printable':
                $body = quoted_printable_decode( $body );
                break;
        }

        file_put_contents( $filepath, $body );

        return $filepath;
    }

    /**
     * ポインタを先頭に戻す
     *
     * @return void
     */
    public function rewind()
    {
        $this->position = 0;
    }

    /**
     * メールを削除する
     *
     * @return boolean
     */
    public function delete()
    {
        // メールは存在するか？
        if(
            $this->position > $this->info['total'] ||
            $this->position <= 0
        ) {
            return FALSE;
        }

        try {
            $this->_sendCommand( 'DELE '.$this->position );
            return TRUE;
        } catch( Exception $e ) {
            var_dump( $e->getMessage() );
            return FALSE;
        }
    }

    /**
     * 変数セット
     *
     * @param string $key
     * @param mixed $val
     */
    public function __set( $key, $val )
    {
        $key = strtoupper( $key );

        if(! isset( $this->server[ $key ] ) ) {
            throw new Exception( 'Set Error' );
        }

        $this->server[ $key ] = $val;
    }

    /**
     * コマンド送信
     *
     * @param string $cmd
     * @return string
     */
    protected function _sendCommand( $cmd )
    {
        fputs( $this->fp, $cmd.$this->br );
        return $this->_checkResponce();
    }

    /**
     * コマンド確認
     *
     * @return string
     */
    protected function _checkResponce()
    {
        $buf = fgets( $this->fp, 512 );
        if( substr( $buf, 0, 3 ) !== '+OK' ) {
            fclose( $this->fp );
            throw new Exception( 'Connect Error : '.$buf );
        }
        return $buf;
    }
}
