<?php

namespace ProcessMaker\Listeners;

use ProcessMaker\Models\FormalExpression;
use ProcessMaker\Nayra\Contracts\Bpmn\ActivityInterface;
use ProcessMaker\Nayra\Bpmn\Events\ActivityActivatedEvent;
use ProcessMaker\Nayra\Bpmn\Events\ActivityCompletedEvent;
use ProcessMaker\Nayra\Bpmn\Events\ActivityClosedEvent;
use ProcessMaker\Nayra\Contracts\Bpmn\ProcessInterface;
use ProcessMaker\Nayra\Bpmn\Events\ProcessInstanceCreatedEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use ProcessMaker\Events\ActivityAssigned;
use ProcessMaker\Events\ProcessCompleted;
use ProcessMaker\Events\ActivityCompleted;
use ProcessMaker\Nayra\Contracts\Bpmn\ScriptTaskInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\ServiceTaskInterface;
use ProcessMaker\Nayra\Bpmn\Events\ProcessInstanceCompletedEvent;
use ProcessMaker\Nayra\Contracts\Bpmn\TokenInterface;
use ProcessMaker\Facades\WorkflowManager;
use ProcessMaker\Models\Comment;
use ProcessMaker\Models\ProcessRequest;
use ProcessMaker\Nayra\Contracts\Bpmn\TransitionInterface;
use ProcessMaker\Notifications\ProcessCreatedNotification;
use ProcessMaker\Notifications\ProcessCompletedNotification;
use ProcessMaker\Notifications\ActivityActivatedNotification;
use ProcessMaker\Notifications\ActivityCompletedNotification;
use ProcessMaker\Nayra\Contracts\Bpmn\IntermediateCatchEventInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\MessageEventDefinitionInterface;
use ProcessMaker\Nayra\Contracts\Bpmn\TimerEventDefinitionInterface;

/**
 * Description of BpmnSubscriber
 *
 */
class BpmnSubscriber
{
    /**
     * When a process instance is completed.
     *
     * @param ProcessInstanceCreatedEvent $event
     */
    public function onProcessCompleted(ProcessInstanceCompletedEvent $event)
    {
        if ($event->instance->isNonPersistent()) {
            return;
        }
        Log::info('Process completed: ' . json_encode($event->instance->getProperties()));

        $notifiables = $event->instance->getNotifiables('completed');
        Notification::send($notifiables, new ProcessCompletedNotification($event->instance));
        event(new ProcessCompleted($event->instance));
    }

    /**
     * When a process instance is created.
     *
     * @param ProcessInstanceCreatedEvent $event
     */
    public function onProcessCreated(ProcessInstanceCreatedEvent $event)
    {
        if ($event->instance->isNonPersistent()) {
            return;
        }
        Log::info('Process created: ' . json_encode($event->instance->getProperties()));

        $notifiables = $event->instance->getNotifiables('started');
        Notification::send($notifiables, new ProcessCreatedNotification($event->instance));
    }

    /**
     * When an activity is activated.
     *
     * @param ActivityActivatedEvent $event
     */
    public function onActivityActivated(ActivityActivatedEvent $event)
    {
        $token = $event->token;
        if ($token->getInstance()->isNonPersistent()) {
            return;
        }
        Log::info('Activity activated: ' . json_encode($token->getProperties()));

        $notifiables = $token->getNotifiables('assigned');
        Notification::send($notifiables, new ActivityActivatedNotification($token));
        event(new ActivityAssigned($event->token));
    }

    /**
     * When the user completes a task.
     *
     * @param $event
     */
    public function onActivityCompleted(ActivityCompletedEvent $event)
    {
        $token = $event->token;
        if ($token->getInstance()->isNonPersistent()) {
            return;
        }
        Log::info('Activity completed: ' . json_encode($token->getProperties()));

        if ($token->element_type == 'task') {
            $notifiables = $token->getNotifiables('completed');
            Notification::send($notifiables, new ActivityCompletedNotification($token));
            event(new ActivityCompleted($token));
        }
    }

    /**
     * When the activity is closed.
     *
     * @param $event
     */
    public function onActivityClosed(ActivityClosedEvent $event)
    {
        Log::info('ActivityClosed: ' . json_encode($event->token->getProperties()));
    }

    /**
     * When a script task is activated.
     *
     * @param ScriptTaskInterface $scriptTask
     * @param TokenInterface $token
     */
    public function onScriptTaskActivated(ScriptTaskInterface $scriptTask, TokenInterface $token)
    {
        // Log::info('ScriptTaskActivated: ' . $scriptTask->getId());
        WorkflowManager::runScripTask($scriptTask, $token);
    }

    /**
     * When a service task is activated.
     *
     * @param ServiceTaskInterface $serviceTask
     * @param TokenInterface $token
     */
    public function onServiceTaskActivated(ServiceTaskInterface $serviceTask, TokenInterface $token)
    {
        WorkflowManager::runServiceTask($serviceTask, $token);
    }

    public function onIntermediateCatchEventActivated(IntermediateCatchEventInterface $event, TokenInterface $token)
    {
        $messages = [
            MessageEventDefinitionInterface::class => 'System is waiting to receive message ":event"',
            TimerEventDefinitionInterface::class => 'System has scheduled timer: ":event"'
        ];
        foreach ($event->getEventDefinitions() as $eventDefinition) {
            foreach ($messages as $interface => $message) {
                if (is_subclass_of($eventDefinition, $interface)) {
                    $comment = new Comment([
                        'user_id' => null,
                        'commentable_type' => ProcessRequest::class,
                        'commentable_id' => $token->getInstance()->id,
                        'subject' => __($message, ['event' => $event->getName()]),
                        'body' => __($message, ['event' => $event->getName()]),
                        'type' => 'LOG'
                    ]);
                    $comment->save();
                    break;
                }
            }
        }
    }

    public function updateDataWithFlowTransition($transition, $flow, $instance)
    {
        // Exit job if flow doesn't have set a config attribute
        if (empty($flow->getProperties()['config'])) {
            return;
        }

        // Exit if config is not a valid json
        if (empty(json_decode($flow->getProperties()['config']))) {
            Log::error('Flow config attribut is not a valid json');
            return;
        }

        // Exit if no variable or expression is set
        $config = json_decode($flow->getProperties()['config'], true);
        if ( empty($config['update_data'])
            || empty($config['update_data']['variable'])
            || empty($config['update_data']['expression'])
        ) {
            return;
        }

        try {
            $variable = $config['update_data']['variable'];
            $expression = $config['update_data']['expression'];

            $formalExp = new FormalExpression();
            $formalExp->setLanguage('FEEL');
            $formalExp->setBody($expression);
            $data = $instance->getDataStore()->getData();
            $expressionResult = $formalExp($data);
            $data = array_merge($data, [$variable => $expressionResult]);
            $data = $data;
            $instance->getDataStore()->setData($data);
            if (!$instance->isNonPersistent()) {
                $instance->data = $data;
                $instance->saveOrFail();
            }
        } catch (\Exception $e) {
            Log::error('The expression used in the flow generated and error: ', [$e->getMessage()]);
            $instance->logError($e, $transition->getOwner());
        }
    }

    /**
     * Subscription.
     *
     * @param type $events
     */
    public function subscribe($events)
    {
        $events->listen(TransitionInterface::EVENT_CONDITIONED_TRANSITION, static::class . '@updateDataWithFlowTransition');


        $events->listen(ProcessInterface::EVENT_PROCESS_INSTANCE_CREATED, static::class . '@onProcessCreated');
        $events->listen(ProcessInterface::EVENT_PROCESS_INSTANCE_COMPLETED, static::class . '@onProcessCompleted');

        $events->listen(ActivityInterface::EVENT_ACTIVITY_COMPLETED, static::class . '@onActivityCompleted');
        $events->listen(ActivityInterface::EVENT_ACTIVITY_CLOSED, static::class . '@onActivityClosed');

        $events->listen(ActivityInterface::EVENT_ACTIVITY_ACTIVATED, static::class . '@onActivityActivated');
        $events->listen(ScriptTaskInterface::EVENT_SCRIPT_TASK_ACTIVATED, static::class . '@onScriptTaskActivated');
        $events->listen(ServiceTaskInterface::EVENT_SERVICE_TASK_ACTIVATED, static::class . '@onServiceTaskActivated');

        $events->listen(IntermediateCatchEventInterface::EVENT_CATCH_TOKEN_ARRIVES, static::class . '@onIntermediateCatchEventActivated');
    }
}
