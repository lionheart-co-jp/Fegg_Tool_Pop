# Fegg Tool Pop

POPサーバに接続してメールを取得する[Fegg](https://github.com/genies-inc/Fegg)向けの拡張ライブラリです。

## 使用例

```php
$pop = $this->getClass( 'Tool/Pop' );

// 接続情報
$pop->host = 'mail.example.com';
$pop->user = 'mail_user';
$pop->pass = 'mail_pass';
$pop->host = '110';

// 接続
$pop->connect();

// メール取得
while( $pop->fetch() ) {
    // ヘッダー
    $header = $pop->getHeader();
    // メール本文
    $body = $pop->getBody();
    // 添付ファイル
    $attches = $pop->getAttachFile();

    // メール削除
    $pop->delete();
}

// 切断
$pop->close();
```