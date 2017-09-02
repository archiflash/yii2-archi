<?php


namespace archiflash\archi;

use Yii;
use yii\base\Exception;
use yii\db\Connection;

use DOMDocument;
/**
 * ContactForm is the model behind the contact form.
 */
class MailParser
{
    private $inbox;
    private $debug_info = "";
    private $sql_commands = [];
    private $projects = [];
    private $authors = [];
    private $posts = [];
    private $project_users = [];
    private $connection;
    private $result = false;
    private $total_posts = 0;
    private $mail_credentials = [];
    private $db_credentials = [];
    

    public function __construct($db_credentials, $mail_credentials)
    {

          $this->mail_credentials = $mail_credentials;

          $this->db_credentials = $db_credentials;

    }
    public function parse()
    {

          $inbox = $this->connect();
                                 
          $str = "";

          $this->inbox = $inbox;

          $this->connectDB();

          $this->prepareSQL();

          $message = [];

          $message_count = imap_num_msg($inbox);

                          //$message_count
          for ($i=3; $i<=3; $i++) {

              $header = imap_header($inbox, $i); 
              
              $structure = imap_fetchstructure($inbox, $i);

              $message["date"] = strtotime($header->MailDate) - 24*60*60;

              $parts = $structure->parts;
              
              $message["digests"] = [];

              for ($j=1;$j<3;$j++) {//count($parts)

                 $body  = imap_fetchbody($inbox, $i,$j+1);

                 $boundry = $parts[$j]->parts[0]->parameters[0]->value;

                 $html = imap_qprint(explode($boundry,$body)[2]);

                 $message["digests"][] = $this->parseEML($html);

              }

              $this->insertDigests($message);

          }                                        
          
          $this->insertPosts();

          $this->closeDB();

          $this->disconnect();

          $this->result = true;

          $this->debug_info = ($this->total_posts)." posts for ".count($this->projects)." projects of ".count($this->authors)." authors";

          return $this->debug_info;
    }

    public function insertPosts()
    {  

        foreach ($this->posts as $project_id=>$posts){
            
            foreach ($posts as $author_id=>$author_posts){

                foreach ($author_posts as $post) {

                    $this->sql_commands["insert_post"]->bindValue(':author_id', $author_id);
                    $this->sql_commands["insert_post"]->bindValue(':project_id', $project_id);
                    //$this->sql_commands["insert_post"]->bindValue(':created_at', $project_id);
                    //$this->sql_commands["insert_post"]->bindValue(':updated_at', $project_id);
                    $this->sql_commands["insert_post"]->bindValue(':body', $post);

                    $this->sql_commands["insert_post"]->execute();

                    $post_id = $this->connection->getLastInsertID();

                    $this->bindPostToUsers($project_id,$post_id);

                    $this->total_posts++;

                }

            }
    
        }

    }
    
    public function bindPostToUsers($project_id,$post_id)
    {          

       foreach($this->project_users[$project_id] as $user_id){

          $this->sql_commands["insert_post_to_user"]->bindValue(':post_id', $post_id);

          $this->sql_commands["insert_post_to_user"]->bindValue(':user_id', $user_id);

          $this->sql_commands["insert_post_to_user"]->execute();

       }

    }

    public function insertDigests($val)
    {

        foreach ($val["digests"] as $projects) {

            foreach ($projects as $project) {

                $project_id = $this->getProjectId($project["name"],$val["date"]);

                foreach ($project["posts"] as $post) {

                   $author_id = $this->getAuthorId($post["author"],$val["date"]);

                   if (!in_array($post["post"],$this->posts[$project_id][$author_id])) {
                                                                                        
                      $this->posts[$project_id][$author_id][] = $post["post"];

                   }

                }
            }
        }

    }

    public function parseEML($val)
    {

        $digest = [];

        $project_separator = "<!-- Project name starts here -->";

        $post_separator = "<!-- Each post = new table -->";

        $projects = explode($project_separator,$val);

        for ($i=1;$i<count($projects);$i++) {
            
            $posts = explode($post_separator,$projects[$i]);

            $digest[$i-1] = [];

            $digest[$i-1]["name"] = $this->getContext($posts[0])[0];

            $digest[$i-1]["posts"] = [];
            
            for ($j=1;$j<count($posts);$j++) {

                 $digest[$i-1]["posts"][$j-1]["author"] = explode(" ",$this->getContext($posts[$j])[0])[0];

                 $digest[$i-1]["posts"][$j-1]["post"] = $this->getContext($posts[$j])[1];

            }

        }

       return $digest;

    }

    public function getContext($val)
    {
        $result = [];
        
        $doc = new DOMDocument();

        $doc->loadHTML('<?xml encoding="UTF-8">'.$val);   

        $tds = $doc->getElementsByTagName('td');
        
        foreach ($tds as $td) {

            $result[] = trim($td->textContent);

        }
        
        return $result;

    }

    public function getProjectId($name,$date)
    {

        if ($this->projects[$name]) {

             return  $this->projects[$name];

        }

        $this->sql_commands["get_project"]->bindValue(':name', $name);

        $result = $this->sql_commands["get_project"]->queryOne();
        
        if ($result===false) {

             $this->sql_commands["insert_project"]->bindValue(':name', $name);

             $this->sql_commands["insert_project"]->execute();

             $id = $this->connection->getLastInsertID();

             $this->projects[$name] = $id;

        }else{
        
            $id = $this->projects[$name] = $result["id"];
        }

        $this->project_users[$id] = $this->getProjectUsers($id);

        return $id;

    }

    public function getProjectUsers($id)
    {

        if ($this->project_users[$id]) {

             return  $this->project_users[$id];

        }

        $this->sql_commands["get_users"]->bindValue(':project_id', $id);

        $result = $this->sql_commands["get_users"]->queryColumn();
        
        if ($result===false) {

             return  [];

        }
        
        $this->project_users[$id] = $result;

        return $result;

    }

    public function getAuthorId($author,$date)
    {

        if ($this->authors[$author]) {

             return  $this->authors[$author];

        }

        $this->sql_commands["get_user"]->bindValue(':username', $author);

        $result = $this->sql_commands["get_user"]->queryOne();
        
        if ($result===false) {

             $key = "";
             $hash = Yii::$app->getSecurity()->generatePasswordHash("0000");
             $email = strtolower($author)."@siberian.pro";
             $this->sql_commands["insert_user"]->bindValue(':username', $author);
             $this->sql_commands["insert_user"]->bindValue(':auth_key', $key);
             $this->sql_commands["insert_user"]->bindValue(':password_hash', $hash);
             $this->sql_commands["insert_user"]->bindValue(':email', $email);
             //$this->sql_commands["insert_user->bindValue(':created_at', $date);
             //$this->sql_commands["insert_user->bindValue(':updated_at', $date);

             $this->sql_commands["insert_user"]->execute();

             $id = $this->connection->getLastInsertID();

             $this->authors[$author] = $id;

             return  $id;

        }

        $this->authors[$author] = $result["id"];
        
        return $result["id"];

    }

    public function connectDB()
    {

        $connection = new \yii\db\Connection([

        'dsn' => $this->db_credentials["dsn"],
        'username' => $this->db_credentials["username"],
        'password' => $this->db_credentials["password"],

        ]);

        try {

            $connection->open();

            $this->connection = $connection;

        } catch (\Exception $e) {

	    throw new Exception('Connection error: ' . $e);
        }

    }

    public function prepareSQL()
    {

        $connection = $this->connection;
    
        $this->sql_commands["get_user"] = $connection->createCommand('SELECT id FROM user WHERE username=:username');
        $this->sql_commands["insert_user"] = $connection->createCommand('INSERT INTO user(username,auth_key,password_hash,email,created_at,updated_at) VALUES(:username,:auth_key,:password_hash,:email,NOW(),NOW())');
        $this->sql_commands["get_project"] = $connection->createCommand('SELECT id FROM project WHERE name=:name');
        $this->sql_commands["insert_project"] = $connection->createCommand('INSERT INTO project(name) values(:name)');
        $this->sql_commands["insert_post"] = $connection->createCommand('INSERT INTO post(author_id,project_id,created_at,updated_at,body) values(:author_id,:project_id,NOW(),NOW(),:body)');
        $this->sql_commands["get_users"] = $connection->createCommand('SELECT user_id FROM project_to_user WHERE project_id=:project_id');
        $this->sql_commands["insert_post_to_user"] = $connection->createCommand('INSERT INTO users_to_post(post_id,user_id) values(:post_id,:user_id)');

    }

    public function closeDB()
    {

         $this->connection->close();

    }

    public function connect()
    {
 
        $imapPath = $this->mail_credentials["imapPath"];
        $username = $this->mail_credentials["username"];
        $password = $this->mail_credentials["password"];

        $inbox = imap_open($imapPath,$username,$password);

        $check = imap_check($inbox);

        if (!$inbox) {

	    throw new Exception('Connection error: ' . imap_last_error());

	}

        return $inbox;
    }

    public function disconnect() 
    {
        if($this->inbox) {

            imap_close($this->inbox, CL_EXPUNGE);

        }
		
    }
}
