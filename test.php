<?php

function removeTag($content, $tagName) {
  $dom = new DOMDocument();
  $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);

  $nodes = $dom->getElementsByTagName($tagName);
  while ($node = $nodes->item(0)) {
      $replacement = $dom->createDocumentFragment();
      while ($inner = $node->childNodes->item(0)) {
          $replacement->appendChild($inner);
      }
      $node->parentNode->replaceChild($replacement, $node);
  }

  # remove <!DOCTYPE
  $dom->removeChild($dom->doctype);

  $nodes = $dom->getElementsByTagName('html');
  while ($node = $nodes->item(0)) {
      $replacement = $dom->createDocumentFragment();
      while ($inner = $node->childNodes->item(0)) {
          $replacement->appendChild($inner);
      }
      $node->parentNode->replaceChild($replacement, $node);
  }

  $nodes = $dom->getElementsByTagName('body');
  while ($node = $nodes->item(0)) {
      $replacement = $dom->createDocumentFragment();
      while ($inner = $node->childNodes->item(0)) {
          $replacement->appendChild($inner);
      }
      $node->parentNode->replaceChild($replacement, $node);
  }

  return str_replace('<?xml encoding="utf-8" ?>', '', $dom->saveHTML());
}

$string = '😱<p>I&apos;m still reading so many reactions to <a href="https://mastodon.social/tags/google" class="mention hashtag" rel="tag">#<span>Google</span></a>+ shutting down to consumers rather soon. Most are taking this opportunity to own back their content.</p><p>Have you started a blog yet? </p><p><a href="https://www.ricardtorres.com/rant/google-plus-shutting-down/" rel="nofollow noopener" target="_blank"><span class="invisible">https://www.</span><span class="ellipsis">ricardtorres.com/rant/google-p</span><span class="invisible">lus-shutting-down/</span></a> <br /> <br /><a href="https://mastodon.social/tags/blogging" class="mention hashtag" rel="tag">#<span>Blogging</span></a> <a href="https://mastodon.social/tags/google" class="mention hashtag" rel="tag">#<span>Google</span></a></p>';


print_r(removeTag($string, 'span'));