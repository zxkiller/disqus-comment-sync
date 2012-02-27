<?php
/*
 * Synchronize Disqus comments to a local database via their API.
 * 
 * Will fetch all new comments and threads since it was last run.
 * 
 * You must use our forked version of the Disqus API (https://github.com/allerinternett/disqus-php) 
 * for this script to work.
 * 
 * Schedule script to run daily via your local scheduling program.
 * 
 * Read the README file for more information.
 * 
 * 
 * @copyright Aller Internett AS (it@allerinternett.no)
 * @author    JustAdam
 * @package   disqus-comment-sync
 * @version   0.1.1
 * @license   GPL v3
 * 
 */

// Max. of 100
define('FETCH_LIMIT', 100);
// asc or desc
define('FETCH_ORDER', 'asc');
// API key to use when quering the API
define('DISQUS_API_KEY', 'YOUR_KEY_HERE');
// List of forum short names as listed at Disqus
$forum_shortnames = array(
        'forum_name',
        'forum_name_other'
);

$db_connect = 'mysql:host=localhost;port=3306;dbname=disqus';
$db_user = 'username';
$db_pass = 'password';

// Local location to our Disqus API fork .. (https://github.com/allerinternett/disqus-php)
require_once('api/disqus-php/disqusapi/disqusapi.php');


$dbh = new PDO($db_connect, $db_user, $db_pass);
// We run two queries; one to fetch Disqus forum threads, and one to fetch the comments.  
// We need to be able to link each comment to an article (or thread), and this information is 
// not available in the API's comment response (when using you own identifiers).  These processes 
// are not tied together (or dependant upon each other), so it is in theory possible to have 
// comments backed up without any parent thread.
$threads = $dbh->prepare("insert into disqus_threads (id, ident, forum, created) values (:id, :ident, :forum, :created)");
$comments = $dbh->prepare("insert into disqus_comments (forum, isApproved, author_name, author_url, avatar_url, author_email, author_id, author_our_id, isAnonymous, message, ip_address, thread_id, comment_id, parent_id, created, isSpam, isDeleted, isEdited, likes)
  values (:forum, :is_approved, :author_name, :author_url, :avatar_url, :author_email, :author_id, :author_our_id, :is_anonymous, :message, :ip_address, :thread_id, :comment_id, :parent_id, :created, :is_spam, :is_deleted, :is_edited, :likes)");


try {
  $disqus = new DisqusAPI(DISQUS_API_KEY, 'json', '3.0');
  
  foreach ($forum_shortnames as $forum) {
    //
    // Back up forum threads ... these are needed to reference back each comment to an article ID
    //
    // Arguments to send to Disqus > http://disqus.com/api/docs/threads/list/, http://disqus.com/api/docs/posts/list/
    // We will also send since and cursor, but these are added later as needed.
    $params = array('forum' => $forum, 'order' =>  FETCH_ORDER, 'limit' => FETCH_LIMIT);

    // Get the latest comment date downloaded so we request only comments made since then
    $res = $dbh->query("select max(created) as max from disqus_threads where forum = '$forum'")->fetch();
    if (!empty($res['max'])) {
      $params['since'] = $res['max'];
    }

    do {
      $posts = $disqus->threads->list($params);
      
      // Create cursor to paginate through resultset
      $cursor = $posts->cursor;
      
      // Update our arguments with the cursor and the next position
      $params['cursor'] = $cursor->next;

      foreach ($posts as $post) {
        $threads->bindValue(':id', $post->id);
        $threads->bindValue(':ident', @$post->identifiers[0]);
        $threads->bindValue(':forum', $forum);
        $threads->bindValue(':created', strtotime($post->createdAt));
        $threads->execute();
      }
    } while ($cursor->more);
    // End forum threads
    
    //
    // Now fetch the actual comments ..
    //
    // Reset the "changeable" paramaters being sent to Disqus.
    unset($params['since']);
    unset($params['cursor']);

    unset($res);
    $res = $dbh->query("select max(created) as max from disqus_comments where forum = '$forum'")->fetch();
    if (!empty($res['max'])) {
      $params['since'] = $res['max'];
    }
    
    do {
      $posts = $disqus->posts->list($params);
      $cursor = $posts->cursor;
      
      $params['cursor'] = $cursor->next;

      foreach ($posts as $post) {
        $comments->bindValue(':forum', $forum);
        $comments->bindValue(':is_approved', $post->isApproved);
        $comments->bindValue(':author_name', $post->author->name);
        $comments->bindValue(':author_url', $post->author->url);
        $comments->bindValue(':author_email', $post->author->email);
        $comments->bindValue(':avatar_url', @$post->author->avatar->permalink);
        $comments->bindValue(':author_id', @$post->author->id);
        $comments->bindValue(':author_our_id', @$post->author->remote->identifier);
        $comments->bindValue(':is_anonymous', $post->author->isAnonymous);
        $comments->bindValue(':message', $post->raw_message);
        $comments->bindValue(':ip_address', $post->ipAddress);
        $comments->bindValue(':thread_id', $post->thread);
        $comments->bindValue(':comment_id', $post->id);
        $comments->bindValue(':parent_id', $post->parent);
        $comments->bindValue(':is_spam', $post->isSpam);
        $comments->bindValue(':is_deleted', $post->isDeleted);
        $comments->bindValue(':is_edited', $post->isEdited);
        $comments->bindValue(':likes', $post->likes);
        $comments->bindValue(':created', strtotime($post->createdAt));
        $comments->execute();
      }
    } while ($cursor->more);
  }
} catch (DisqusAPIError $e) {
  echo $e->getMessage();
  echo PHP_EOL;
  exit;
}