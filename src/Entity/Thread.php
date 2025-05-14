<?php

namespace Drupal\match_messaging\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityChangedTrait;

/**
 * Defines the Thread entity.
 *
 * @ContentEntityType(
 * id = "match_thread",
 * label = @Translation("Match Thread"),
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 * "views_data" = "Drupal\views\EntityViewsData",
 * "form" = {
 * "default" = "Drupal\Core\Entity\ContentEntityForm",
 * "add" = "Drupal\Core\Entity\ContentEntityForm",
 * "edit" = "Drupal\Core\Entity\ContentEntityForm",
 * "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 * },
 * "route_provider" = {
 * "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 * },
 * "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 * },
 * base_table = "match_thread",
 * admin_permission = "administer match messaging entities",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "label" = "id",
 * "owner" = "user_id",
 * },
 * links = {
 * "add-form" = "/admin/structure/match_thread/add",
 * "edit-form" = "/admin/structure/match_thread/{match_thread}/edit",
 * "delete-form" = "/admin/structure/match_thread/{match_thread}/delete",
 * "collection" = "/admin/structure/match_thread",
 * },
 * field_ui_base_route = "entity.match_thread.settings"
 * )
 */
class Thread extends ContentEntityBase implements ContentEntityInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Thread entity.'))
      ->setReadOnly(TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Thread entity.'))
      ->setReadOnly(TRUE);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setDescription(t('The user ID of the entity owner (initiator).'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback('Drupal\match_messaging\Entity\Thread::getCurrentUserId')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user1'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User 1'))
      ->setDescription(t('The first user in the thread.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user2'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User 2'))
      ->setDescription(t('The second user in the thread.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_message_timestamp'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last Message Time'))
      ->setDescription(t('The time the last message was sent in this thread.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // --- NEW FIELDS for individual upload agreement ---
    $fields['user1_agrees_to_uploads'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('User 1 Agrees to Uploads'))
      ->setDescription(t('Indicates if User 1 agrees to allow image uploads in this thread.'))
      ->setDefaultValue(FALSE) // Default to not allowing uploads until explicitly agreed
      ->setDisplayOptions('form', [ // For admin edit form
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE); // Not typically shown directly

    $fields['user2_agrees_to_uploads'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('User 2 Agrees to Uploads'))
      ->setDescription(t('Indicates if User 2 agrees to allow image uploads in this thread.'))
      ->setDefaultValue(FALSE) // Default to not allowing uploads until explicitly agreed
      ->setDisplayOptions('form', [ // For admin edit form
        'type' => 'boolean_checkbox',
        'settings' => ['display_label' => TRUE],
        'weight' => 21,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE); // Not typically shown directly
    // --- END NEW FIELDS ---

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

  /**
   * Default value callback for 'user_id' base field definition.
   */
  public static function getCurrentUserId(): array
  {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * Checks if a given user is a participant in this thread.
   */
  public function isParticipant(UserInterface $user): bool
  {
    $user_id = $user->id();
    $user1_id = !$this->get('user1')->isEmpty() ? $this->get('user1')->target_id : NULL;
    $user2_id = !$this->get('user2')->isEmpty() ? $this->get('user2')->target_id : NULL;
    return ($user1_id == $user_id || $user2_id == $user_id);
  }

  /**
   * Gets the other participant in this two-person thread.
   */
  public function getOtherParticipant(UserInterface $user): ?UserInterface
  {
    $user_id = $user->id();
    $user1_target_id = !$this->get('user1')->isEmpty() ? $this->get('user1')->target_id : NULL;
    $user2_target_id = !$this->get('user2')->isEmpty() ? $this->get('user2')->target_id : NULL;

    if ($user1_target_id == $user_id) {
      return !$this->get('user2')->isEmpty() ? $this->get('user2')->entity : NULL;
    } elseif ($user2_target_id == $user_id) {
      return !$this->get('user1')->isEmpty() ? $this->get('user1')->entity : NULL;
    }
    return NULL;
  }

  /**
   * Checks if uploads are allowed in this thread based on both users' agreement.
   *
   * @return bool
   * TRUE if both participants agree to uploads, FALSE otherwise.
   */
  public function uploadsAllowed(): bool
  {
    $user1_agrees = (bool) $this->get('user1_agrees_to_uploads')->value;
    $user2_agrees = (bool) $this->get('user2_agrees_to_uploads')->value;
    return $user1_agrees && $user2_agrees;
  }

}
