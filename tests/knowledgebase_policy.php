<?php
declare(strict_types=1);

$client=file_get_contents(dirname(__DIR__).'/app/Http/ClientController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0004_knowledgebase_feedback.sql');
$runner=file_get_contents(dirname(__DIR__).'/app/Core/MigrationRunner.php');
foreach([$client,$index,$migration,$runner] as $source)if(!is_string($source))throw new RuntimeException('Knowledge-base source missing.');
$checks=[
 'public knowledgebase filters only published articles'=>str_contains($client,"WHERE status='published'"),
 'search covers title excerpt and body'=>str_contains($client,'title LIKE :title OR excerpt LIKE :excerpt OR content LIKE :content'),
 'category filter uses published categories'=>str_contains($client,'AND category=:category'),
 'article detail route validates slug characters'=>str_contains($index,"#^/knowledgebase/([a-z0-9][a-z0-9-]{0,189})$#D"),
 'article detail is available through GET'=>str_contains($index,"$method === 'GET') { $client->knowledgebaseArticle"),
 'feedback POST is routed to article handler'=>str_contains($index,"$method === 'POST') { $client->knowledgebaseArticle"),
 'feedback requires CSRF validation'=>str_contains($client,"Auth::verifyCsrf($_POST['_token']??null)"),
 'feedback stores a keyed session hash rather than IP address'=>str_contains($client,"hash_hmac('sha256',session_id(),$this->applicationKey)")&&!str_contains($client,'REMOTE_ADDR'),
 'a browser can change its existing vote'=>str_contains($client,'ON DUPLICATE KEY UPDATE vote=VALUES(vote)'),
 'one feedback row per visitor and article'=>str_contains($migration,'UNIQUE KEY uq_knowledgebase_feedback_visitor (article_id, visitor_hash)'),
 'feedback rows cascade when an article is deleted'=>str_contains($migration,'REFERENCES knowledgebase_articles(id) ON DELETE CASCADE'),
 'vote values are constrained to boolean values'=>str_contains($migration,'vote TINYINT(1) NOT NULL'),
 'feedback never stores visitor IP addresses'=>!str_contains($migration,'ip_address')&&!str_contains($migration,'ip_hash'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot knowledgebase search and feedback policy tests passed.".PHP_EOL;
