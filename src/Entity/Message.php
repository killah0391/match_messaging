<?php

namespace Drupal\match_messaging\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityChangedTrait;

/**
 * Defines the Message entity.
 *
 * @ContentEntityType(
 * id = "match_message",
 * label = @Translation("Match Message"),
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 * "views_data" = "Drupal\views\EntityViewsData",
 * "form" = {
 * "default" = "Drupal\match_messaging\Form\MessageForm",
 * "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 * },
 * "route_provider" = {
 * "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 * },
 * "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 * },
 * base_table = "match_message",
 * admin_permission = "administer match messaging entities",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "label" = "id",
 * "owner" = "sender",
 * },
 * links = {
 * "delete-form" = "/admin/structure/match_message/{match_message}/delete",
 * "collection" = "/admin/structure/match_message",
 * },
 * field_ui_base_route = "entity.match_message.canonical"
 * )
 */
class Message extends ContentEntityBase implements ContentEntityInterface
{

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Standard field: ID
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Message entity.'))
      ->setReadOnly(TRUE);

    // Standard field: UUID
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Message entity.'))
      ->setReadOnly(TRUE);

    // Reference to the Thread this message belongs to.
    $fields['thread_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Thread'))
      ->setDescription(t('The thread this message belongs to.'))
      ->setSetting('target_type', 'match_thread')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'entity_reference_label',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE); // Allow configuring on form display


    // Sender of the message (owner).
    $fields['sender'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Sender'))
      ->setDescription(t('The user who sent the message.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'author', // Shows username
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);


    // Message body. (No longer required if an image is provided)
    $fields['body'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Message Body'))
      ->setDescription(t('The content of the message.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Images')) // Label for administrative UI
      ->setDescription(t('Images attached to the message.'))
      ->setCardinality(3) // Allow up to 3 images
      // --- MODIFIED: Use private storage and keep date tokens for organization ---
      ->setSetting('uri_scheme', 'private') // Explicitly set to private
      ->setSetting('file_directory', 'chat_images/[date:custom:Y]-[date:custom:m]')
      // --- END MODIFICATION ---
      ->setSetting('handler', 'default:file')
      ->setSetting('file_extensions', 'png gif jpg jpeg')
      ->setSetting('max_filesize', '2M')
      ->setSetting('alt_field', FALSE)
      ->setSetting('title_field', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image',
        'settings' => [
          'image_link' => 'file',
          'image_style' => 'medium',
        ],
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'label' => 'above', // This controls label display in form, can be overridden in "Manage form display"
        'type' => 'image_image',
        'weight' => 1, // Default weight, can be overridden by $form['image']['#weight'] in MessageForm.php
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);


    // Timestamps: Created (Sent time)
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Sent'))
      ->setDescription(t('The time that the message was sent.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 10,
        'settings' => [
          'date_format' => 'medium', // Adjust format as needed
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // Timestamps: Changed
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'))
      ->setDisplayOptions('view', [ // Usually hidden for messages
        'label' => 'hidden',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);


    return $fields;
  }
}
