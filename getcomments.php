<?php
/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

require_once 'Mastodon_api.php';
require_once '../mastodon_config.php';
include_once("../wp-load.php");

class CollectMastodonData {

    /** @var \Mastodon_api */
    private $api;

    /** @var string url of the mastodon instance */
    private $mastodonUrl = 'https://mastodon.social';

    /** @var string token to authenticate at the mastodon instance */
    private $bearerToken;

    /** @var int keep cache at least 600 seconds = 10 minutes */
    public $threshold = 600;

    /** @var int keep cache of found root too for 4 weeks (redis only)
     *
     * This threshold only applies if a root was found, otherwise the
     * normal threshold applies.
     * */
    public $rootThreshold = 28 * 24 * 60 * 60;

    /** @var string uid on the mastodon instance */
    private $uid;

    /** @var Redis redis instance */
    private $redis;

    /** @var array cached comments from previous searches */
    private $commentCache = [];
    private $cacheFile = 'myCommentsCache.json';

    public function __construct($config) {
        $this->mastodonUrl = $config['mastodon-instance'];
        $this->bearerToken = $config['token'];
        $this->uid = $config['user-id'];

        $this->api = new Mastodon_api();
        $this->api->set_url($this->mastodonUrl);
        $this->api->set_token($this->bearerToken, 'bearer');

        if (array_key_exists('redis-url', $config) && $config['redis-url']) {
            $this->redis = new Redis();
            $this->redis->pconnect($config['redis-url']);
        }
    }

    private function filterComments($descendants, $root) {
        $results = [];

        foreach ($descendants as $d) {
            $results[$d['id']] = [
                'author' => [
                    'display_name' => $d['account']['display_name'] ? $d['account']['display_name'] : $d['account']['username'],
                    'avatar' => $d['account']['avatar_static'],
                    'url' => $d['account']['url']
                ],
                'toot' => $d['content'],
                'toot_id' => $d['id'],
                'date' => $d['created_at'],
                'url' => $d['uri'],
                'reply_to' => $d['in_reply_to_id'],
                'root' => $root
            ];
        }

        return $results;
    }

    private function filterStats($stats) {
        $result = [
            'reblogs' => (int)$stats['reblogs_count'],
            'favs' => (int)$stats['favourites_count'],
            'replies' => (int)$stats['replies_count'],
            'url' => $stats['url']
        ];
        return $result;
    }

    private function filterSearchResults($searchResult) {
        $result = [];
        if (isset($searchResult['html']['statuses'])) {
            foreach ($searchResult['html']['statuses'] as $status) {
                if ($status['in_reply_to_id'] === null) {
                    $result[] = $status['id'];
                }
            }
        }
        return $result;
    }

    /**
     * find all toots for a given blog post and return the corresponding IDs
     *
     * @param string $search
     * @return array
     */
    public function findToots($search) {
        if ($this->redis) {
            $resultJSON = $this->redis->get("roots/$search");
            if ($resultJSON) {
                return json_decode($resultJSON);
            }
        }

        // $result = $this->filterSearchResults($this->api->search(['q' => $search]));
        $result = $this->api->search(['q' => $search]);

        if ($this->redis) {
            $this->redis->set("roots/$search", json_encode($result));
            $this->redis->expire("roots/$search", isset($result[0]) ? $this->rootThreshold : $this->threshold);
        }
        return $result;
    }

    public function getComments($id) {
        $raw = file_get_contents("https://mastodon.social/api/v1/statuses/$id/context");
        $json = json_decode($raw, true);
        return $this->filterComments($json['descendants'], $id);
    }

    public function getStatistics($id, &$result) {
        $raw = file_get_contents("https://mastodon.social/api/v1/statuses/$id");
        $json = json_decode($raw, true);
        $newStats = $this->filterStats($json);
        $result['stats']['reblogs'] += $newStats['reblogs'];
        $result['stats']['favs'] += $newStats['favs'];
        $result['stats']['replies'] += $newStats['replies'];
        if (empty($result['stats']['url'])) {
            $result['stats']['url'] = $newStats['url'];
        }
    }

    public function storeCollection($id, $comments) {
        if ($this->redis) {
            $this->redis->set("comments/$id", json_encode($comments));
            $this->redis->expire("comments/$id", $this->threshold);
        } else {
            $this->commentCache[$id] = $comments;
            file_put_contents($this->cacheFile, json_encode($this->commentCache));
        }
    }

    public function getCachedCollection($search) {
        if ($this->redis) {
            return json_decode($this->redis->get("comments/$search"), true);
        }
        if (file_exists($this->cacheFile)) {
            $cachedComments = file_get_contents($this->cacheFile);
            $cachedCommentsArray = json_decode($cachedComments, true);
            if (is_array($cachedCommentsArray)) {
                $this->commentCache = $cachedCommentsArray;
                $currentTimestamp = time();
                if (isset($cachedCommentsArray[$search])) {
                    if ((int)$cachedCommentsArray[$search]['timestamp'] + $this->threshold > $currentTimestamp) {
                        unset($cachedCommentsArray[$search]['timestamp']);
                        return $cachedCommentsArray[$search];
                    }
                }
            }
        }

        return [];
    }
}


function removeTag($content, $tagName) {
    $dom = new DOMDocument();
    $dom->loadXML($content);

    $nodes = $dom->getElementsByTagName($tagName);

    while ($node = $nodes->item(0)) {
        $replacement = $dom->createDocumentFragment();
        while ($inner = $node->childNodes->item(0)) {
            $replacement->appendChild($inner);
        }
        $node->parentNode->replaceChild($replacement, $node);
    }

    return $dom->saveHTML();
}


// Define the Comment Meta Key
$comment_meta_key = "toot_id";

// For my email notification
$posts_with_new_comments = [];

// get_posts the latest 50 posts
// The API limit should be 300 request in 5 minutes
// Quering the latest 50 posts should be enough. No need to add comments to those old posts.
$args = array(
	'posts_per_page'   => 50,
	'orderby'          => 'date',
	'order'            => 'DESC',
	'post_type'        => 'post',
	'post_status'      => 'publish'
);
$posts_array = get_posts( $args );

foreach ( $posts_array as $post ) {
    setup_postdata( $post );

    // Here we should ge the current POST url and pass it as the $search, just the slug.
    // If you try with the full URL it doesn't find it. It seems a limitation from Mastodon itself.
    // Example: /rant/enjoying-a-roaming-free-europe/
    $categories = get_the_category($post->ID);
    foreach ($categories as $category) {
        $search = "/" . $category->slug . "/" . $post->post_name;
        break;
    }

    $collector = new CollectMastodonData($config);
    $results = $collector->findToots($search);

    if ($results['html']['statuses'] && sizeof($results['html']['statuses'])) {
        foreach ( $results['html']['statuses'] as $status ) {
            if ($status['visibility'] === "public") {
                // Check if a comment with the toot_id already exists
                $args = array(
                    'meta_key' => $comment_meta_key,
                    'meta_value' => $status['id']
                );
                // https://codex.wordpress.org/Class_Reference/WP_Comment_Query
                $comments_query = new WP_Comment_Query;
                $comments = $comments_query->query( $args );

                if ( $comments ) {
                    // It exist, we'll use it as parent in case the toot has replies
                    foreach ( $comments as $comment ) {
                        $comment_parent_id = $comment->commend_ID;
                        break;
                    }
                } else {
                    // Does not exist, create a new parent comment
                    $toot_url = $status['url'];
                    $content = removeTag($status['content'], 'span') . '<br><br><a href="'. $toot_url .'" rel="nofollow">Original Toot</a>';
                    $commentdata = array(
                        'comment_post_ID' => $post->ID,
                        'comment_author' => $status['account']['display_name'],
                        'comment_author_url' => $status['account']['url'],
                        'comment_content' => $content,
                        'comment_date' => $status['created_at'],
                        'comment_approved' => 1,
                        'comment_parent' => 0 // 0 if it's not a reply to another comment
                    );

                    // Check if it's me, then add my email
                    if ($status['account']['username'] === "ricard_dev") {
                        $commentdata['comment_author_email'] = "torres.rick@gmail.com";
                    }

                    print_r($args); die();

                    // https://codex.wordpress.org/Function_Reference/wp_insert_comment
                    $comment_parent_id = wp_insert_comment( $commentdata, true);

                    // Use comment meta to store the toot id
                    // https://codex.wordpress.org/Function_Reference/add_comment_meta
                    add_comment_meta( $comment_parent_id, $comment_meta_key, $status['id'], false );

                    array_push($posts_with_new_comments, get_permalink($post->ID));
                }

                // Find if the toot has replies
                $replies = $collector->getComments($status['id'], $results);

                if (sizeof($replies)) {
                    foreach ( $replies as $reply ) {
                        // Check if a reply comment with the toot_id already exists
                        $args = array(
                            'meta_key' => $comment_meta_key,
                            'meta_value' => $reply['toot_id']
                        );
                        $comments_query = new WP_Comment_Query;
                        $comments = $comments_query->query( $args );

                        if ( !$comments ) {
                            // No replies with this ID
                            // Let's add the comment as reply to the main one
                            $toot_url = $reply['url'];
                            $content = removeTag($reply['toot'], 'span') . '<br><br><a href="'. $toot_url .'" rel="nofollow">Original Toot</a>';
                            $commentdata = array(
                                'comment_post_ID' => $post->ID,
                                'comment_author' => $reply['author']['display_name'],
                                'comment_author_url' => $reply['author']['url'],
                                'comment_content' => $content,
                                'comment_date' => $status['date'],
                                'comment_approved' => 1,
                                'comment_parent' => $comment_parent_id
                            );
                            $comment_id = wp_insert_comment( $commentdata, true);

                            // Use comment meta to store the toot id
                            // https://codex.wordpress.org/Function_Reference/add_comment_meta
                            add_comment_meta( $comment_id, $comment_meta_key, $reply['toot_id'], false );

                            array_push($posts_with_new_comments, get_permalink($post->ID));
                        }
                    }
                }
            }
        }
    }
}
wp_reset_postdata();

// Email me a notification
$email_content = "";
foreach ($posts_with_new_comments as $post) {
    $email_content .= $post . "\n";
}

mail("torres.rick@gmail.com", "New Comments", $email_content);