<?php

namespace Drupal\match_messaging\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\match_messaging\Entity\Thread;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface; // Import EntityFormBuilderInterface
use Drupal\user_match\Service\UserMatchService; // Import UserMatchService

/**
 * Controller for match_messaging routes.
 */
class ChatController extends ControllerBase
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * The user match service.
   *
   * @var \Drupal\user_match\Service\UserMatchService
   */
  protected $userMatchService;

  /**
   * The entity form builder.
   *
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;


  /**
   * Constructs a ChatController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   * The form builder.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   * The entity repository.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   * The UUID service.
   * @param \Drupal\user_match\Service\UserMatchService $user_match_service
   *   The user match service.
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entity_form_builder
   *   The entity form builder.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    FormBuilderInterface $form_builder,
    EntityRepositoryInterface $entity_repository,
    UuidInterface $uuid_service,
    UserMatchService $user_match_service,
    EntityFormBuilderInterface $entity_form_builder // Add EntityFormBuilderInterface
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->formBuilder = $form_builder;
    $this->entityRepository = $entity_repository;
    $this->uuidService = $uuid_service;
    $this->userMatchService = $user_match_service; // Assign UserMatchService
    $this->entityFormBuilder = $entity_form_builder; // Assign EntityFormBuilder
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('form_builder'),
      $container->get('entity.repository'),
      $container->get('uuid'),
      $container->get('user_match.service'),
      $container->get('entity.form_builder') // Get EntityFormBuilder service
    );
  }

  /**
   * Starts a chat with a given user using user1/user2 fields.
   *
   * This method finds an existing thread or creates a new one based on
   * the two participant user IDs, ensuring they are stored consistently,
   * and initializes upload agreement fields.
   *
   * @param \Drupal\user\UserInterface $user
   * The user to start a chat with.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * A redirect response to the thread view page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   * If the current user is anonymous.
   */
  public function startChat(UserInterface $user): RedirectResponse
  {
    // Ensure the current user is not anonymous.
    if ($this->currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to start a chat.');
    }

    // Prevent users from starting a chat with themselves.
    if ($this->currentUser->id() == $user->id()) {
      $this->messenger()->addError($this->t("You cannot start a chat with yourself."));
      return $this->redirect('<front>');
    }

    $senderUser = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $recipientUser = $user; // $user is already the loaded UserInterface object for the recipient

    if (!$senderUser || !$recipientUser) {
      $this->messenger()->addError($this->t("Could not load user profiles."));
      return $this->redirect('<front>');
    }

    // Check recipient's message acceptance preference.
    $acceptsOnlyFromMatches = FALSE;
    if ($recipientUser->hasField('field_accept_msg_from_matches') && !$recipientUser->get('field_accept_msg_from_matches')->isEmpty()) {
      $acceptsOnlyFromMatches = (bool) $recipientUser->get('field_accept_msg_from_matches')->value;
    }

    if ($acceptsOnlyFromMatches) {
      $isMutualMatch = $this->userMatchService->checkForMatch($senderUser->id(), $recipientUser->id());
      if (!$isMutualMatch) {
        $this->messenger()->addError($this->t('@username only accepts messages from mutual matches. You cannot start a chat at this time.', ['@username' => $recipientUser->getAccountName()]));
        return $this->redirect('<front>'); // Or redirect to a more appropriate page like user's profile.
      }
    }

    $user1_id = (int) $senderUser->id();
    $user2_id = (int) $recipientUser->id();

    // Ensure consistent ordering of user IDs (e.g., smaller ID first)
    // This is crucial for the query to reliably find existing threads.
    $participants_query_values = [$user1_id, $user2_id];
    sort($participants_query_values); // Sort to ensure user1 always holds the smaller ID in the query

    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $query = $thread_storage->getQuery();
    // Query for (user1=sorted[0] AND user2=sorted[1])
    // This assumes that when threads are created, user1 always stores the smaller ID
    // and user2 always stores the larger ID.
    $query->condition('user1', $participants_query_values[0])
      ->condition('user2', $participants_query_values[1])
      ->accessCheck(TRUE) // Respect entity access controls.
      ->range(0, 1); // We only expect one such thread.
    $thread_ids = $query->execute();

    $thread = NULL;
    if (!empty($thread_ids)) {
      $thread = $thread_storage->load(reset($thread_ids));
    }

    if (!$thread) {
      // Create a new thread.
      // Ensure consistent storage: smaller ID in user1, larger in user2.
      $thread = Thread::create([
        'user1' => $participants_query_values[0], // Store smaller ID in user1
        'user2' => $participants_query_values[1], // Store larger ID in user2
        'user_id' => $this->currentUser->id(),    // Set the owner/initiator
        'last_message_timestamp' => \Drupal::time()->getRequestTime(), // Initialize
        // Initialize new agreement fields to FALSE.
        'user1_agrees_to_uploads' => FALSE,
        'user2_agrees_to_uploads' => FALSE,
      ]);
      $thread->save();
      $this->messenger()->addStatus($this->t('Created a new chat with @username.', ['@username' => $user->getAccountName()]));
    }

    if ($thread && $thread->uuid()) {
      // Redirect to the UUID-based route.
      // Using existing route 'match_messaging.thread_view' and parameter 'thread_uuid'.
      $url = Url::fromRoute('match_messaging.thread_view', ['thread_uuid' => $thread->uuid()]);
      return new RedirectResponse($url->toString());
    } else {
      $this->messenger()->addError($this->t('Could not start or find the chat thread.'));
      return $this->redirect('<front>');
    }
  }

  /**
   * Displays a chat thread.
   *
   * @param string $thread_uuid
   * The UUID of the thread to display.
   *
   * @return array
   * A render array containing the chat messages and the message form, themed.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   * Thrown if the thread with the given UUID is not found.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   * Thrown if the current user is not a participant in the thread.
   */
  public function viewThread(string $thread_uuid): array
  {
    $threads = $this->entityTypeManager->getStorage('match_thread')->loadByProperties(['uuid' => $thread_uuid]);

    if (empty($threads)) {
      throw new NotFoundHttpException();
    }
    /** @var \Drupal\match_messaging\Entity\Thread $thread */
    $thread = reset($threads);

    $current_user_entity = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    // The isParticipant method in Thread.php now uses user1/user2 fields.
    if (!$current_user_entity || !$thread->isParticipant($current_user_entity)) {
      throw new AccessDeniedHttpException('You do not have permission to view this chat thread.');
    }

    // Load messages for this thread.
    $message_storage = $this->entityTypeManager->getStorage('match_message');
    $message_ids = $message_storage->getQuery()
      ->accessCheck(TRUE) // Access check for messages is important.
      ->condition('thread_id', $thread->id())
      ->sort('created', 'ASC')
      ->execute();

    $messages = $message_storage->loadMultiple($message_ids);

    $message_view_builder = $this->entityTypeManager->getViewBuilder('match_message');
    $rendered_messages = [];
    $message_cache_tags = [];
    if (!empty($messages)) {
      $rendered_messages = $message_view_builder->viewMultiple($messages, 'full');
      foreach ($messages as $m) { // Use a different variable name to avoid conflict
        $message_cache_tags = array_merge($message_cache_tags, $m->getCacheTags());
      }
      $message_cache_tags = array_unique($message_cache_tags);
    }

    // Get the message form.
    $new_message = $message_storage->create([
      'thread_id' => $thread->id(),
      'sender' => $this->currentUser->id(),
    ]);
    // Use the injected entityFormBuilder property
    $message_form = $this->entityFormBuilder->getForm($new_message, 'default');

    // Determine the other participant for the title using the updated getOtherParticipant method.
    $other_participant = $thread->getOtherParticipant($current_user_entity);
    $page_title = $other_participant ? $this->t('Chat with @username', ['@username' => $other_participant->getAccountName()]) : $this->t('Chat Thread');

    // Assemble the page content using the theme hook.
    $build = [];
    $build['#title'] = $page_title;

    $build['chat_content'] = [
      '#theme' => 'chat_thread',
      '#thread' => $thread, // Pass the whole thread entity to the template
      '#messages' => $rendered_messages,
      '#form' => $message_form,
      '#current_user_id' => $this->currentUser->id(),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => array_merge(
          $thread->getCacheTags(),
          $message_cache_tags
        ),
      ],
      '#attached' => [
        'library' => [
          'match_messaging/match-messaging-chat', // Attach the new JS library
        ],
      ],
    ];

    return $build;
  }
}
