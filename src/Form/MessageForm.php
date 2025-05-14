<?php

namespace Drupal\match_messaging\Form;

use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Entity\EntityStorageException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\match_messaging\Entity\Thread; // Import Thread entity
use Drupal\Core\Ajax\ReplaceCommand; // Needed for updating form parts
use Drupal\Core\Entity\EntityTypeManagerInterface; // For loading entities
use Drupal\match_messaging\Ajax\ShowMatchMessagingScrollToBottomCommand; // Add this line
use Drupal\Core\StringTranslation\ByteSizeMarkup; // Corrected: Import ByteSizeMarkup from StringTranslation namespace

/**
 * Form controller for the match_message entity edit forms.
 *
 * @ingroup match_messaging
 */
class MessageForm extends ContentEntityForm
{

  /**
   * The Current User object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder; // Add this line

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    $instance = parent::create($container);
    $instance->currentUser = $container->get('current_user');
    if ($container->has('entity_type.manager')) {
      $instance->entityTypeManager = $container->get('entity_type.manager');
    }
    $instance->renderer = $container->get('renderer');
    $instance->formBuilder = $container->get('form_builder'); // Add this line
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    /** @var \Drupal\match_messaging\Entity\Message $message_entity */
    $message_entity = $this->entity;
    $form = parent::buildForm($form, $form_state);

    // Ensure the thread_id is in form state for AJAX callbacks.
    // Assume the controller setting up the form for a new message
    // attaches a new message entity with the thread_id already set:
    // $message = $this->entityTypeManager()->getStorage('match_message')->create([
    //     'thread_id' => $thread_id,
    //     'sender' => $this->currentUser()->id(),
    // ]);
    // $form = $this->entityFormBuilder()->getForm($message);

    /** @var \Drupal\match_messaging\Entity\Thread|null $thread_entity */
    $thread_entity = NULL;
    if (!$message_entity->get('thread_id')->isEmpty()) {
      try {
        $thread_entity = $message_entity->get('thread_id')->entity;
        // Store the thread ID in form state for consistent access in AJAX callbacks.
        $form_state->set('thread_id', $thread_entity->id());
      } catch (\Exception $e) {
        // Log the error if the thread entity cannot be loaded from the reference.
        $this->getLogger('match_messaging')->error('Failed to load thread entity from message entity reference: @error', ['@error' => $e->getMessage()]);
        // Handle the case where the thread reference is broken or entity doesn't exist.
        $this->messenger()->addError($this->t('Could not load the chat thread. Cannot display form.'));
        // You might want to disable the form elements or redirect here if thread is essential.
        // For now, we'll let the rest of the form build and subsequent validation/submission
        // handle the missing context, which will now have thread_entity as NULL.
      }
    } else {
      // Handle the case where the message entity somehow doesn't have a thread_id.
      // This indicates an issue in how the message entity was created before form build.
      $this->messenger()->addError($this->t('Message entity is missing thread context. Cannot display form.'));
      // Disable form elements if no thread context at all.
      $form['#access'] = FALSE; // Disable access to the entire form if no thread context.
    }


    $form['#theme'] = 'message_form';

    // Hide fields
    if (isset($form['sender'])) $form['sender']['#access'] = FALSE;
    if (isset($form['thread_id'])) $form['thread_id']['#access'] = FALSE; // Keep this hidden.
    if (isset($form['created'])) $form['created']['#access'] = FALSE;
    if (isset($form['changed'])) $form['changed']['#access'] = FALSE;
    if (isset($form['uuid'])) $form['uuid']['#access'] = FALSE;

    if (isset($form['body'])) {
      $form['body']['widget'][0]['value']['#title'] = $this->t('Your message (optional if uploading images)');
      $form['body']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('Type your message here...');
    }

    if (isset($form['image'])) unset($form['image']);

    // Wrapper for the image upload field for AJAX replacement.
    $form['field_message_images_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'field-message-images-wrapper'],
    ];
    $form['field_message_images_wrapper']['field_message_images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Attach images'),
      // Use ByteSizeMarkup for the description.
      '#description' => $this->t('You can upload up to 3 images (PNG, GIF, JPG, JPEG). Max size per image: @size.', ['@size' => $this->formatSize(2 * 1024 * 1024)]),
      '#upload_location' => 'private://chat_images/',
      '#multiple' => TRUE,
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg'],
        'file_validate_size' => [2 * 1024 * 1024],
      ],
      '#weight' => 5,
    ];

    /** @var \Drupal\match_messaging\Entity\Thread|null $thread_entity */
    $thread_entity = NULL;
    if (!$message_entity->get('thread_id')->isEmpty()) {
      $thread_entity = $message_entity->get('thread_id')->entity;
    }

    $user_agreement_field_name = '';
    $default_agreement_value = FALSE;

    // Wrapper for the agreement checkbox and its description for AJAX replacement.
    $form['my_agreement_for_uploads_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'my-agreement-for-uploads-wrapper'],
      '#weight' => -5, // Position this checkbox prominently.
    ];


    if ($thread_entity instanceof Thread) {
      $user1_id_on_thread = !$thread_entity->get('user1')->isEmpty() ? $thread_entity->get('user1')->target_id : NULL;
      $user2_id_on_thread = !$thread_entity->get('user2')->isEmpty() ? $thread_entity->get('user2')->target_id : NULL;
      $current_user_id = $this->currentUser->id();

      if ($current_user_id == $user1_id_on_thread) {
        $user_agreement_field_name = 'user1_agrees_to_uploads';
        $default_agreement_value = (bool) $thread_entity->get('user1_agrees_to_uploads')->value;
      } elseif ($current_user_id == $user2_id_on_thread) {
        $user_agreement_field_name = 'user2_agrees_to_uploads';
        $default_agreement_value = (bool) $thread_entity->get('user2_agrees_to_uploads')->value;
      }

      if (!empty($user_agreement_field_name)) {
        $form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('I agree to allow image uploads in this thread'),
          '#default_value' => $default_agreement_value,
          // AJAX settings
          '#ajax' => [
            'callback' => '::ajaxAgreementCheckboxCallback',
            'event' => 'change',
            'wrapper' => 'my-agreement-for-uploads-wrapper',
            'progress' => [
              'type' => 'throbber',
              'message' => $this->t('Saving preference...'),
            ],
          ],
        ];
        $form_state->set('user_agreement_field_name', $user_agreement_field_name);
        $form_state->set('thread_id_for_agreement', $thread_entity->id());
      }

      // Initial state of image uploads and descriptions
      $this->updateUploadsAccessAndDescriptions($form, $thread_entity, $this->currentUser->id(), $default_agreement_value);
    } else {
      $form['field_message_images_wrapper']['field_message_images']['#access'] = FALSE;
      $this->messenger()->addWarning($this->t('Thread context not found. Image uploads disabled.'));
    }

    $form['actions']['submit']['#value'] = $this->t('Send Message');
    if (!isset($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'] = [];
    }
    array_unshift($form['actions']['submit']['#submit'], '::submitAgreement');

    // Add AJAX settings for the main submit button.
    $form['actions']['submit']['#ajax'] = [
      'callback' => '::ajaxSubmitMessage',
      'event' => 'click',
      'progress' => [
        'type' => 'throbber',
        'message' => $this->t('Sending message...'),
      ],
    ];

    return $form;
  }

  /**
   * Helper function to update access for image uploads and descriptions.
   */
  protected function updateUploadsAccessAndDescriptions(array &$form, Thread $thread_entity, $current_user_id, $current_user_agreement_status)
  {
    $uploads_allowed = method_exists($thread_entity, 'uploadsAllowed') && $thread_entity->uploadsAllowed();

    if (!$uploads_allowed) {
      if (isset($form['field_message_images_wrapper']['field_message_images'])) {
        $form['field_message_images_wrapper']['field_message_images']['#access'] = FALSE;
      }
      if (isset($form['body']['widget'][0]['value'])) {
        $form['body']['widget'][0]['value']['#title'] = $this->t('Your message (image uploads are currently disabled for this thread)');
      }
      if (isset($form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads'])) {
        $other_user_agreed = FALSE;
        $user1_id_on_thread = !$thread_entity->get('user1')->isEmpty() ? $thread_entity->get('user1')->target_id : NULL;

        if ($current_user_id == $user1_id_on_thread && !$thread_entity->get('user2_agrees_to_uploads')->isEmpty()) {
          $other_user_agreed = (bool) $thread_entity->get('user2_agrees_to_uploads')->value;
        } elseif ($current_user_id != $user1_id_on_thread && !$thread_entity->get('user1_agrees_to_uploads')->isEmpty()) {
          $other_user_agreed = (bool) $thread_entity->get('user1_agrees_to_uploads')->value;
        }

        if ($current_user_agreement_status && !$other_user_agreed) {
          $form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads']['#description'] = $this->t('You have agreed. Waiting for the other participant to agree for uploads to be enabled.');
        } elseif (!$current_user_agreement_status) {
          $form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads']['#description'] = $this->t('Check this box to agree to image uploads. The other participant must also agree.');
        } else {
          $form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads']['#description'] = $this->t('Image uploads are disabled as both participants have not agreed.');
        }
      }
    } else {
      if (isset($form['field_message_images_wrapper']['field_message_images'])) {
        $form['field_message_images_wrapper']['field_message_images']['#access'] = TRUE;
      }
      if (isset($form['body']['widget'][0]['value'])) {
        $form['body']['widget'][0]['value']['#title'] = $this->t('Your message (optional if uploading images)');
      }
      if (isset($form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads'])) {
        $form['my_agreement_for_uploads_wrapper']['my_agreement_for_uploads']['#description'] = $this->t('Image uploads are enabled as both participants have agreed. You can uncheck this to withdraw your agreement.');
      }
    }
  }


  /**
   * AJAX callback for the 'my_agreement_for_uploads' checkbox.
   */
  public function ajaxAgreementCheckboxCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    $agreement_field_name = $form_state->get('user_agreement_field_name');
    $thread_id = $form_state->get('thread_id_for_agreement');
    $agreement_value = (bool) $form_state->getValue('my_agreement_for_uploads');

    if ($agreement_field_name && $thread_id) {
      try {
        /** @var \Drupal\match_messaging\Entity\Thread|null $thread */
        $thread = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);

        if ($thread instanceof Thread) {
          $thread->set($agreement_field_name, $agreement_value);
          $thread->save();
          $response->addCommand(new MessageCommand($this->t('Your preference for image uploads has been saved.')));

          $this->updateUploadsAccessAndDescriptions($form, $thread, $this->currentUser->id(), $agreement_value);

          if (isset($form['my_agreement_for_uploads_wrapper'])) {
            $response->addCommand(new ReplaceCommand('#my-agreement-for-uploads-wrapper', $form['my_agreement_for_uploads_wrapper']));
          }
          if (isset($form['field_message_images_wrapper'])) {
            $response->addCommand(new ReplaceCommand('#field-message-images-wrapper', $form['field_message_images_wrapper']));
          }
        } else {
          $response->addCommand(new MessageCommand($this->t('Could not save preference: thread not found.'), 'error'));
        }
      } catch (EntityStorageException $e) {
        $response->addCommand(new MessageCommand($this->t('An error occurred while saving your preference.'), 'error'));
      } catch (\Exception $e) {
        $response->addCommand(new MessageCommand($this->t('An unexpected error occurred.'), 'error'));
      }
    } else {
      $response->addCommand(new MessageCommand($this->t('Could not save preference: missing information.'), 'error'));
    }

    return $response;
  }


  /**
   * Custom submit handler to save the user's upload agreement.
   */
  public function submitAgreement(array &$form, FormStateInterface $form_state)
  {
    $agreement_field_name = $form_state->get('user_agreement_field_name');
    $thread_id = $form_state->get('thread_id_for_agreement');

    if ($agreement_field_name && $thread_id && $form_state->hasValue('my_agreement_for_uploads')) {
      $agreement_value_from_form = $form_state->getValue('my_agreement_for_uploads');
      $agreement_value = (bool) $agreement_value_from_form;

      try {
        $thread_storage = $this->entityTypeManager->getStorage('match_thread');
        /** @var \Drupal\match_messaging\Entity\Thread|null $thread */
        $thread = $thread_storage->load($thread_id);

        if ($thread instanceof Thread) {
          $current_db_value = (bool) $thread->get($agreement_field_name)->value;

          if ($current_db_value !== $agreement_value) {
            $thread->set($agreement_field_name, $agreement_value);
            $thread->save();
          }
        }
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('An error occurred while updating your agreement status. Please try again.'));
      }
    }
  }

  /**
   * AJAX callback for the message form submission.
   */
  public function ajaxSubmitMessage(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    // Validate the form first.
    $this->validateForm($form, $form_state);

    // If there are validation errors, rebuild the form to show them.
    // We need to rebuild the form array so that it contains the validation messages.
    // Then render only the parts that might contain errors (body and image wrapper).
    if ($form_state->hasAnyErrors()) {
      $form_state->setRebuild(); // Mark for rebuild to get errors in form array
      $rebuilt_form_array = $this->buildForm($form, $form_state); // Rebuild the form array

      // Replace the form body field wrapper to show errors next to it.
      if (isset($rebuilt_form_array['body'])) {
        // Assuming 'body' field is in a wrapper or can be targeted.
        // If not, you might need to adjust your Twig template or target a parent.
        // For standard field rendering, the field is often wrapped.
        $body_rendered = $this->renderer->renderRoot($rebuilt_form_array['body']);
        // We need a specific wrapper around the body field in the Twig template
        // if it's not provided by default. Let's assume a wrapper with ID 'message-body-wrapper'.
        // If your body field is directly in the form and doesn't have a specific wrapper,
        // replacing just the field might not work well. Replacing the whole form wrapper
        // with errors showing might still be necessary in that case.
        // However, let's try replacing the body field container first.
        // Let's assume a wrapper around the body field in the Twig template like:
        // <div id="message-body-wrapper">{{ form.body }}</div>
        if (isset($form['body']['#wrapper_id'])) { // Check if a wrapper ID was set in buildForm
          $response->addCommand(new ReplaceCommand('#' . $form['body']['#wrapper_id'], $body_rendered));
        } else {
          // Fallback or assume direct replacement is possible (less reliable).
          // This might target the outer div provided by Drupal for the field.
          $response->addCommand(new ReplaceCommand('#edit-body', $body_rendered)); // Adjust selector as needed
        }
      }

      // Replace the image upload field wrapper to show errors.
      if (isset($rebuilt_form_array['field_message_images_wrapper'])) {
        $image_wrapper_rendered = $this->renderer->renderRoot($rebuilt_form_array['field_message_images_wrapper']);
        $response->addCommand(new ReplaceCommand('#field-message-images-wrapper', $image_wrapper_rendered));
      }


      // Add a message indicating there were errors.
      // Messages added via $form_state->setErrorByName are automatically displayed by Drupal's
      // status message system if they are rendered on the page.
      // However, an explicit message command can ensure visibility in AJAX contexts.
      // $response->addCommand(new MessageCommand($this->t('Please fix the errors in the form.'), 'error')); // Optional

      return $response; // Return the response with errors.
    }


    /** @var \Drupal\match_messaging\Entity\Message $entity */
    // Get the message entity from the form state, which should be populated
    // with submitted values after validation.
    $entity = $form_state->getFormObject()->getEntity();


    /** @var \Drupal\match_messaging\Entity\Thread|null $thread_entity */
    $thread_entity = NULL;
    // Get thread ID from form state, set in buildForm.
    $thread_id = $form_state->get('thread_id');

    if ($thread_id) {
      try {
        $thread_entity = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);
      } catch (\Exception $e) {
        $this->getLogger('match_messaging')->error('Error loading thread @thread_id for AJAX message submission: @error', [
          '@thread_id' => $thread_id,
          '@error' => $e->getMessage(),
        ]);
        $response->addCommand(new MessageCommand($this->t('Error processing message: thread not found.'), 'error'));
        // We don't rebuild the form on this error, as the context (thread) is missing.
        return $response; // Exit if thread not found.
      }
    } else {
      // This case should ideally be caught by validateForm, but as a safeguard:
      $response->addCommand(new MessageCommand($this->t('Chat thread context is missing. Cannot send message.'), 'error'));
      // No rebuild needed if context is fundamentally missing.
      return $response; // Exit if thread ID is missing.
    }


    // Handle file uploads manually within the AJAX callback.
    // The managed_file element handles the temporary saving. We need to finalize them
    // and ensure their IDs are correctly associated with the message entity.
    $image_fids_value = $form_state->getValue('field_message_images', []);
    $image_fids = is_array($image_fids_value) ? $image_fids_value : [$image_fids_value];
    $image_items = [];
    $uploads_allowed_in_thread = $thread_entity instanceof Thread && $thread_entity->uploadsAllowed();

    if (!empty($image_fids) && $uploads_allowed_in_thread) {
      $file_storage = $this->entityTypeManager->getStorage('file');
      foreach ($image_fids as $fid) {
        if (!empty($fid)) {
          try {
            $file = $file_storage->load($fid);
            if ($file) {
              // If the file is temporary, make it permanent.
              if ($file->isTemporary()) {
                $file->setPermanent();
                $file->save(); // Save the file entity to update its status.
              }
              // Add the file reference to the list for the message entity's 'image' field.
              $image_items[] = ['target_id' => $fid];

              // Store the permanent file ID in the form state storage
              // so that Drupal's file usage system can track it
              // when the message entity is finally saved.
              // Use getStorage() which returns a persistent array for this form state.
              // Initialize if not set.
              $storage = $form_state->getStorage();
              $storage['file_upload_fids'] = $storage['file_upload_fids'] ?? [];
              if (!in_array($fid, $storage['file_upload_fids'])) {
                $storage['file_upload_fids'][] = $fid;
              }
              $form_state->setStorage($storage); // Ensure updated storage is set back.


            } else {
              // Log if a submitted FID doesn't correspond to a file entity.
              $this->getLogger('match_messaging')->warning('Submitted FID @fid not found during AJAX message save.', ['@fid' => $fid]);
              // Optionally add a user message.
            }
          } catch (\Exception $e) {
            $this->getLogger('match_messaging')->error('Error finalizing file upload for FID @fid during AJAX save: @error', [
              '@fid' => $fid,
              '@error' => $e->getMessage(),
            ]);
            // Add a message to the user, but continue saving the text message if any.
            $response->addCommand(new MessageCommand($this->t('An error occurred while processing one or more images.'), 'warning'));
          }
        }
      }
      // Set the image field value on the message entity *before* calling parent::save().
      $entity->set('image', $image_items);
    } else {
      // If uploads are not allowed or no images were uploaded, ensure the image field is empty on the entity.
      $entity->set('image', []);
    }

    // Ensure the sender is set for new messages.
    if ($entity->isNew() && $entity->get('sender')->isEmpty()) {
      $entity->set('sender', $this->currentUser->id());
    }
    // Ensure thread_id is set for new messages.
    if ($entity->isNew() && $entity->get('thread_id')->isEmpty() && $thread_entity) {
      $entity->set('thread_id', $thread_entity->id());
    }


    try {
      // Save the message entity. This should now correctly handle file usage
      // because we've set the 'image' field value on the entity and marked files permanent.
      $status = parent::save($form, $form_state);

      if ($status == SAVED_NEW || $status == SAVED_UPDATED) {
        // Message saved successfully.

        // 1. Reload and display the messages for the thread.
        if ($thread_entity) {
          $message_storage = $this->entityTypeManager->getStorage('match_message');
          $message_ids = $message_storage->getQuery()
            ->accessCheck(TRUE)
            ->condition('thread_id', $thread_entity->id())
            ->sort('created', 'ASC')
            ->execute();
          $messages = $message_storage->loadMultiple($message_ids);

          $message_view_builder = $this->entityTypeManager->getViewBuilder('match_message');
          $rendered_messages = $message_view_builder->viewMultiple($messages, 'full');

          // Render the messages and replace the messages area.
          $messages_output = $this->renderer->render($rendered_messages);
          $response->addCommand(new HtmlCommand('#chat-messages-area', $messages_output));

          // 2. Scroll the chat window to the bottom.
          // Add the ShowMatchMessagingScrollToBottomCommand. Need to ensure it's used.
          // Assuming this command class exists and the JS is attached.
          $response->addCommand(new \Drupal\match_messaging\Ajax\ShowMatchMessagingScrollToBottomCommand('#chat-messages-area'));


          // 3. Update the thread's last message timestamp.
          $thread_entity->set('last_message_timestamp', \Drupal::time()->getRequestTime());
          $thread_entity->save();

          // 4. Add a success message (optional for chat, as messages appear immediately).
          // $response->addCommand(new MessageCommand($this->t('Message sent.'), 'status'));


        } else {
          // This case is less likely after the thread loading checks, but kept for safety.
          $response->addCommand(new MessageCommand($this->t('Message saved, but could not refresh messages.'), 'warning'));
        }

        // 5. Clear the form fields (body and image) using targeted replacement.

        // Clear the message body field value.
        // This can be done by setting the form state value to empty and then
        // rebuilding and replacing just the body field's render element.
        $form_state->setValue('body', ['0' => ['value' => '']]); // Set body value to empty

        // Rebuild the form *array* to reflect the cleared body and empty file field state.
        // We only need to rebuild the parts we are going to replace.
        $form_state->setRebuild(); // Mark for rebuild

        // Get the render array for the body field.
        $rebuilt_form_array = $this->buildForm($form, $form_state);

        // Replace the body field's render element. Need a wrapper in Twig.
        if (isset($rebuilt_form_array['body'])) {
          $body_rendered = $this->renderer->renderRoot($rebuilt_form_array['body']);
          // Assuming a wrapper around the body field in the Twig template like:
          // <div id="message-body-wrapper">{{ form.body }}</div>
          // Or target the standard field wrapper if it exists and is stable.
          // Let's use a specific wrapper ID 'message-body-wrapper' and assume it's in Twig.
          $response->addCommand(new ReplaceCommand('#message-body-wrapper', $body_rendered)); // Adjust selector as needed

        }

        // Rebuild and replace the image upload field wrapper to clear it.
        // This is necessary to reset the managed_file element state.
        if (isset($rebuilt_form_array['field_message_images_wrapper'])) {
          $image_wrapper_rendered = $this->renderer->renderRoot($rebuilt_form_array['field_message_images_wrapper']);
          $response->addCommand(new ReplaceCommand('#field-message-images-wrapper', $image_wrapper_rendered));
        }

        // If you also want to clear the agreement checkbox description if it changed status,
        // you'd need to replace its wrapper too, similar to the image wrapper.
        // if (isset($rebuilt_form_array['my_agreement_for_uploads_wrapper'])) {
        //      $agreement_wrapper_rendered = $this->renderer->renderRoot($rebuilt_form_array['my_agreement_for_uploads_wrapper']);
        //      $response->addCommand(new ReplaceCommand('#my-agreement-for-uploads-wrapper', $agreement_wrapper_rendered));
        // }


      } else {
        // Handle cases where save didn't return SAVED_NEW or SAVED_UPDATED.
        $this->getLogger('match_messaging')->error('Message entity save did not return SAVED_NEW or SAVED_UPDATED.');
        $response->addCommand(new MessageCommand($this->t('An unexpected issue occurred while saving the message.'), 'error'));
        // Rebuild the form array to show potential issues.
        $form_state->setRebuild();
        $rebuilt_form_array = $this->buildForm($form, $form_state);
        // Render the form array into an HTML string.
        // Replacing the whole form wrapper is safer for showing errors across multiple fields.
        $form_output_html = $this->renderer->renderRoot($rebuilt_form_array);
        $response->addCommand(new ReplaceCommand('#message-form-wrapper', $form_output_html));
      }
    } catch (EntityStorageException $e) {
      // Handle database errors during message save.
      $this->getLogger('match_messaging')->error('Error saving message entity: @error', ['@error' => $e->getMessage()]);
      $response->addCommand(new MessageCommand($this->t('An error occurred while sending your message. Please try again.'), 'error'));
      // Rebuild the form array to show the error message.
      $form_state->setRebuild();
      $rebuilt_form_array = $this->buildForm($form, $form_state);
      // Render the form array into an HTML string.
      // Replacing the whole form wrapper is safer for showing errors across multiple fields.
      $form_output_html = $this->renderer->renderRoot($rebuilt_form_array);
      $response->addCommand(new ReplaceCommand('#message-form-wrapper', $form_output_html));
    } catch (\Exception $e) {
      // Catch any other unexpected exceptions.
      $this->getLogger('match_messaging')->error('An unexpected error occurred during AJAX message submission: @error', ['@error' => $e->getMessage()]);
      $response->addCommand(new MessageCommand($this->t('An unexpected error occurred. Please try again.'), 'error'));
      // Rebuild the form array.
      $form_state->setRebuild();
      $rebuilt_form_array = $this->buildForm($form, $form_state);
      // Render the form array into an HTML string.
      // Replacing the whole form wrapper is safer for showing errors across multiple fields.
      $form_output_html = $this->renderer->renderRoot($rebuilt_form_array);
      $response->addCommand(new ReplaceCommand('#message-form-wrapper', $form_output_html));
    }


    return $response;
  }


  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $body_value_array = $form_state->getValue('body');
    $body_value = isset($body_value_array[0]['value']) ? $body_value_array[0]['value'] : '';
    $image_fids = $form_state->getValue('field_message_images');
    $image_is_empty = empty($image_fids);
    $body_is_empty = empty(trim($body_value));

    /** @var \Drupal\match_messaging\Entity\Message $message_entity */
    $message_entity = $this->entity;
    /** @var \Drupal\match_messaging\Entity\Thread|null $thread_entity */
    $thread_entity = NULL;
    $thread_id_for_agreement = $form_state->get('thread_id_for_agreement');

    if ($thread_id_for_agreement) {
      try {
        $thread_entity = $this->entityTypeManager->getStorage('match_thread')->load($thread_id_for_agreement);
      } catch (\Exception $e) {
        $thread_entity = NULL;
      }
    } elseif (!$message_entity->get('thread_id')->isEmpty()) {
      $thread_entity = $message_entity->get('thread_id')->entity;
    }

    // Determine if uploads are allowed in this thread.
    $uploads_allowed_in_thread = FALSE; // Default to false
    if ($thread_entity instanceof Thread && method_exists($thread_entity, 'uploadsAllowed')) {
      $uploads_allowed_in_thread = $thread_entity->uploadsAllowed();
    }

    if ($body_is_empty) {
      if ($uploads_allowed_in_thread) {
        if ($image_is_empty) {
          $form_state->setErrorByName('body', $this->t('Please enter a message or upload at least one image.'));
          // Removed setErrorByName for 'field_message_images' without a message,
          // as the error on 'body' is sufficient and this was causing an empty toast.
        }
      } else {
        $form_state->setErrorByName('body', $this->t('Please enter a message. Image uploads are currently disabled for this thread.'));
      }
    }

    if (!$image_is_empty && !$uploads_allowed_in_thread) {
      $form_state->setErrorByName('field_message_images', $this->t('Image uploads are not permitted in this thread at this time. Please remove the uploaded files. Your agreement status may have changed.'));
    }

    if (!$image_is_empty && is_array($image_fids) && count($image_fids) > 3) {
      $form_state->setErrorByName('field_message_images', $this->t('You can upload a maximum of 3 images.'));
    }
  }


  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state)
  {
    /** @var \Drupal\match_messaging\Entity\Message $entity */
    $entity = $this->entity;

    /** @var \Drupal\match_messaging\Entity\Thread|null $thread_entity */
    $thread_entity = NULL;
    $thread_id_for_agreement = $form_state->get('thread_id_for_agreement');
    if ($thread_id_for_agreement) {
      try {
        $thread_entity = $this->entityTypeManager->getStorage('match_thread')->load($thread_id_for_agreement);
      } catch (\Exception $e) {
      }
    } elseif (!$entity->get('thread_id')->isEmpty()) {
      $thread_entity = $entity->get('thread_id')->entity;
    }

    $image_fids = $form_state->getValue('field_message_images');
    if (!empty($image_fids) && $uploads_allowed_in_thread) {
      $image_items = [];
      foreach ($image_fids as $fid) {
        if (!empty($fid)) {
          $image_items[] = ['target_id' => $fid];
        }
      }
      $entity->set('image', $image_items);
    } else {
      $entity->set('image', []);
    }

    if ($entity->isNew()) {
      $entity->set('sender', $this->currentUser->id());
    }

    $status = parent::save($form, $form_state);

    if (!$form_state->getRedirect() && $thread_entity instanceof Thread && method_exists($thread_entity, 'uuid')) {
      $thread_uuid = $thread_entity->uuid();
      $form_state->setRedirect(
        'match_messaging.thread_view',
        ['thread_uuid' => $thread_uuid]
      );
    } elseif (!$form_state->getRedirect()) {
      $this->messenger()->addWarning($this->t('Could not redirect back to thread. Thread information missing.'));
      $form_state->setRedirect('<front>');
    }
    return $status;
  }

  /**
   * Helper function to format size in a human-readable way.
   *
   * @param int $size
   * The size in bytes.
   *
   * @return \Drupal\Core\StringTranslation\ByteSizeMarkup
   * The formatted size as a ByteSizeMarkup object.
   */
  protected function formatSize($size)
  {
    // ByteSizeMarkup::create() is the direct way to get a renderable object in Drupal 10+.
    // No service injection is needed for this specific class/method.
    return ByteSizeMarkup::create($size);
  }
}
