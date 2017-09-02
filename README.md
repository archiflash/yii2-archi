тестовое расширение
===================
mail parser

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist archiflash/yii2-archi "*"
```

or add

```
"archiflash/yii2-archi": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by  :

```php


use archiflash\archi\MailParser;


$db_credentials = [];
$db_credentials["dsn"] = 'mysql:dbname=project;host=localhost';
$db_credentials["username"] = 'username';
$db_credentials["password"] = 'password';

$mail_credentials = [];
$mail_credentials["imapPath"] = '{imap.yandex.ru:993/ssl/novalidate-cert/readonly}';
$mail_credentials["username"] = 'username';
$mail_credentials["password"] = 'password';

try {

    $parser = new MailParser($db_credentials, $mail_credentials);

    $result = $parser->parse();

} catch (\Exception $e) {

    $result = $e; 

}
     


```"# yii2-archi" 
