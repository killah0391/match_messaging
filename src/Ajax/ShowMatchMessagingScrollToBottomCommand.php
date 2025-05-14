<?php

namespace Drupal\match_messaging\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Defines an AJAX command for scrolling a chat area to the bottom.
 *
 * This command is processed by JavaScript in match-messaging-chat.js.
 *
 * @AjaxCommand(
 *   command = "matchMessagingScrollToBottom",
 * )
 */
class ShowMatchMessagingScrollToBottomCommand implements CommandInterface
{

  /**
   * The CSS selector for the chat messages area.
   *
   * @var string
   */
  protected $selector;

  /**
   * Constructs a ShowMatchMessagingScrollToBottomCommand object.
   *
   * @param string $selector
   *   The CSS selector for the element to scroll.
   */
  public function __construct(string $selector)
  {
    $this->selector = $selector;
  }

  /**
   * {@inheritdoc}
   */
  public function render()
  {
    return [
      'command' => 'matchMessagingScrollToBottom',
      'selector' => $this->selector,
    ];
  }
}
