/**
 * @file
 * Behaviors for the Match Messaging chat interface.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.matchMessagingChat = {
    attach: function (context, settings) {
      // Initial scroll for page load, and for AJAX replacements if the whole area is replaced.
      // We target elements with 'chat-scroll-init' to ensure it runs once per instantiation.
      const messagesAreas = once('chat-scroll-init', '#chat-messages-area', context);
      messagesAreas.forEach(function (messagesArea) {
        Drupal.behaviors.matchMessagingChat.scrollToBottom(messagesArea);
      });
    },

    /**
     * Scrolls the given element to its bottom.
     *
     * @param {string|Element} selectorOrElement
     *   A CSS selector string or a DOM element.
     */
    scrollToBottom: function (selectorOrElement) {
      var $element = $(selectorOrElement);
      if ($element.length) {
        // Use a slight delay to ensure new content is rendered if called immediately after DOM manipulation.
        // For InvokeCommand, this might not be strictly necessary but doesn't hurt.
        setTimeout(function () {
          $element.scrollTop($element.prop("scrollHeight"));
        }, 50);
      }
    }
  };

  // Define the custom AJAX command handler.
  // This allows PHP to call `Drupal.behaviors.matchMessagingChat.scrollToBottom` via an AJAX response.
  if (Drupal && Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.matchMessagingScrollToBottom = function (ajax, response, status) {
      Drupal.behaviors.matchMessagingChat.scrollToBottom(response.selector);
    };
  }

})(jQuery, Drupal, once);
