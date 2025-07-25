<?php

namespace Minds\Controllers\Cli;

use Minds\Cli;
use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Email\Invites\Services\InviteSenderService;
use Minds\Core\Email\V2\Campaigns;
use Minds\Core\Email\V2\Campaigns\Recurring\Digest\Digest;
use Minds\Core\Email\V2\Campaigns\Recurring\Supermind\Supermind as SupermindEmail;
use Minds\Core\Email\V2\Campaigns\Recurring\SupermindBulkIncentive\SupermindBulkIncentive;
use Minds\Core\Email\V2\Campaigns\Recurring\WireReceived\WireReceived;
use Minds\Core\Email\V2\Campaigns\Recurring\WireSent\WireSent;
use Minds\Core\Email\V2\Delegates\DigestSender;
use Minds\Core\Email\V2\SendLists;
use Minds\Core\Reports;
use Minds\Core\Supermind;
use Minds\Entities\User;
use Minds\Interfaces;
use Minds\Core\Email\V2\Campaigns\Recurring\ForgotPassword\ForgotPasswordEmailer;
use Minds\Core\Email\V2\Campaigns\Recurring\TenantUserWelcome\TenantUserWelcomeEmailer;
use Minds\Core\Email\V2\Campaigns\Recurring\UnreadMessages\UnreadMessages;
use Minds\Core\Email\V2\Campaigns\Recurring\UnreadMessages\UnreadMessagesDispatcher;
use Minds\Core\EntitiesBuilder;
use Minds\Core\MultiTenant\Services\AutoTrialService;
use Minds\Core\MultiTenant\Services\MultiTenantBootService;
use Minds\Core\MultiTenant\Services\MultiTenantDataService;
use Minds\Core\MultiTenant\Services\TenantEmailService;
use Minds\Core\Security\ACL;
use Minds\Core\Security\Password;
use Minds\Exceptions\CliException;

class Email extends Cli\Controller implements Interfaces\CliControllerInterface
{
    private EntitiesBuilder $entitiesBuilder;
    private MultiTenantBootService $multiTenantBootService;
    private TenantEmailService $tenantEmailService;
    private UnreadMessages $unreadMessages;
    private UnreadMessagesDispatcher $unreadMessagesDispatcher;
    private MultiTenantDataService $multiTenantDataService;

    public function __construct()
    {
        $this->entitiesBuilder = Di::_()->get(EntitiesBuilder::class);
        $this->multiTenantBootService = Di::_()->get(MultiTenantBootService::class);
        $this->tenantEmailService = Di::_()->get(TenantEmailService::class);
        $this->unreadMessages = Di::_()->get(UnreadMessages::class);
        $this->unreadMessagesDispatcher = Di::_()->get(UnreadMessagesDispatcher::class);
        $this->multiTenantDataService = Di::_()->get(MultiTenantDataService::class);
    }

    public function help($command = null)
    {
        switch ($command) {
            case 'exec':
                $this->out(file_get_contents(dirname(__FILE__) . '/Help/Email/exec.txt'));
                break;
            case 'testBoostComplete':
                $this->out(file_get_contents(dirname(__FILE__) . '/Help/Email/testBoostComplete.txt'));
                break;
            case 'testWire':
                $this->out(file_get_contents(dirname(__FILE__) . '/Help/Email/testWire.txt'));
                break;
            case 'testWirePromotion':
                $this->out(file_get_contents(dirname(__FILE__) . '/Help/Email/testWirePromotion.txt'));
                break;
            default:
                $this->out('Utilities for testing emails and sending them manually');
                $this->out('try `cli Email {command} --help');
                $this->displayCommandHelp();
        }
    }

    /**
     * TODO: Move this to Core
     * How to run? Eg:
     * php cli.php Email \
     *  --campaign="Marketing\\Languages2020_06_18\\Languages2020_06_18"
     *  --send-list="GenericSendList"
     */
    public function exec()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        $dry = $this->getOpt('dry-run') ?: false;

        $offset = $this->getOpt('offset') ?: '';
        $campaign = Campaigns\Factory::build($this->getOpt('campaign'));
        $sendList = SendLists\Factory::build($this->getOpt('send-list'));
        $sendList->setCampaign($campaign);
        $sendList->setOffset($offset);
        $sendList->setCliOpts($this->getAllOpts());

        $i = 0;
        foreach ($sendList->getList() as $user) {
            if (!$user instanceof User || !method_exists($user, 'getEmail')) {
                continue;
            }
            if ($user->bounced && !$dry) {
                $this->out("[$i]: $user->guid ($sendList->offset) bounced");
                continue;
            }

            ++$i;

            $campaign = clone $campaign;

            if ($campaign instanceof SupermindBulkIncentive) {
                $campaign = $campaign
                    ->withActivityGuid($this->getOpt('activity-guid'))
                    ->withReplyType((int)$this->getOpt('reply-type'))
                    ->withPaymentMethod((int)$this->getOpt('payment-method'))
                    ->withPaymentAmount((int)$this->getOpt('payment-amount'));
            }

            $campaign->setUser($user);

            if (!$dry) {
                $campaign->send();
            }

            $this->out("[$i]: $user->guid ($sendList->offset) sent");
        }

        $this->out('Done.');
    }


    //


    public function testWire()
    {
        $output = $this->getOpt('output');
        $entityGuid = $this->getOpt('guid');
        $senderGuid = $this->getOpt('sender');
        $timestamp = $this->getOpt('timestamp');
        $variant = $this->getOpt('variant');

        $send = $this->getOpt('send');

        $repository = Di::_()->get('Wire\Repository');

        if (!$entityGuid) {
            $this->out('--guid=wire guid required');
            exit;
        }

        if (!$senderGuid) {
            $this->out('--sender=guid required');
            exit;
        }

        if (!$timestamp) {
            $this->out('--timestamp=timestamp required');
            exit;
        }

        $wireResults = $repository->getList([
            'entity_guid' => $entityGuid,
            'sender_guid' => $senderGuid,
            'timestamp' => [
                'gte' => $timestamp,
                'lte' => $timestamp,
            ],
        ]);

        if (!$wireResults || count($wireResults['wires']) === 0) {
            $this->out('Wire not found');
            exit;
        }
        $wire = $wireResults['wires'][0];

        if ($variant === 'sent') {
            $campaign = (new WireSent());
        } elseif ($variant === 'received') {
            $campaign = (new WireReceived());
        } else {
            $this->out('--variant must be sent or received');
            return;
        }

        $campaign
            ->setUser($wire->getReceiver())
            ->setWire($wire);

        $message = $campaign->build();

        if ($send) {
            $campaign->send();
            $this->out('sent');
        }

        if ($output) {
            file_put_contents($output, $message->buildHtml());
        } else {
            $this->out($message->buildHtml());
        }
    }

    /**
     * Example usage:
     *  php cli.php Email testModerationBanned
     * --entityUrn=urn:user:12345
     * --reasonCode=1
     * --subReasonCode=4
     * --output=/var/www/Minds/engine/mod_banned.html
     *
     * reasonCode opt(s) will be used only if
     * your user doesn't have a ban_reason already
     */
    public function testModerationBanned()
    {
        $entityUrn = $this->getOpt('entityUrn');
        $output = $this->getOpt('output');
        $reasonCode = $this->getOpt('reasonCode');
        $subReasonCode = $this->getOpt('subReasonCode');

        if (!$entityUrn) {
            return $this->out('entityUrn must be supplied');
        }

        $banDelegate = new Reports\Verdict\Delegates\EmailDelegate();
        $report = new Reports\Report();
        $report->setEntityUrn($entityUrn);

        if ($reasonCode) {
            $report->setReasonCode($reasonCode);
        }
        if ($subReasonCode) {
            $report->setSubReasonCode($subReasonCode);
        }

        $banDelegate->onBan($report);

        if ($output) {
            $message = $banDelegate->getCampaign()->getMessage();
            file_put_contents($output, $message->buildHtml());
        }
    }

    public function testModerationStrike()
    {
        $entityUrn = $this->getOpt('entityUrn');

        if (!$entityUrn) {
            return $this->out('entityUrn must be supplied');
        }

        $userGuid = $this->getOpt('guid');
        $user = new User($userGuid);

        if (!$userGuid) {
            return $this->out('userGuid must be supplied');
        }

        // Use 8 for strike
        $reasonCode = $this->getOpt('reasonCode');

        $strikeDelegate = new Reports\Strikes\Delegates\EmailDelegate();

        $report = new Reports\Report();
        $report->setEntityUrn($entityUrn);
        $report->setReasonCode($reasonCode);
        $strike = new Reports\Strikes\Strike();
        $strike->setReport($report);

        $strikeDelegate->onStrike($strike);
    }

    /**
     * Example usage:
     *  php cli.php Email testHackedAccount
     * --entityUrn=urn:user:12345
     * --output=/var/www/Minds/engine/mod_banned.html
     */
    public function testHackedAccount()
    {
        $entityUrn = $this->getOpt('entityUrn');
        $output = $this->getOpt('output');


        if (!$entityUrn) {
            return $this->out('entityUrn must be supplied');
        }

        $report = new Reports\Report();
        $report->setEntityUrn($entityUrn);
        $report->setReasonCode(17);
        $report->setSubReasonCode(1);

        $emailDelegate = new Reports\Verdict\Delegates\EmailDelegate();

        $emailDelegate->onHack($report);

        if ($output) {
            $message = $emailDelegate->getCampaign()->getMessage();
            file_put_contents($output, $message->buildHtml());
        }
    }

    /**
     * Test unread message email. Can be sent to a given user OR output as HTML.
     * @param string $userGuid - user guid to send email for.
     * @param string $tenantId - tenant id of the users network (optional - null if main Minds network).
     * @param string $output - output file path (optional).
     * @param string $createdAfterTimestamp - how recent must messages be to be included? (optional - defaults to 24 hours ago).
     * @example
     * - php cli.php Email testUnreadMessages --tenantId=123 --createdAfterTimestamp=1721814873 --userGuid=1285556899399340038 --output=./test.html
     * @return void
     */
    public function testUnreadMessages(): void
    {
        $userGuid = $this->getOpt('userGuid');
        $tenantId = $this->getOpt('tenantId');
        $output = $this->getOpt('output');
        $createdAfterTimestamp = $this->getOpt('createdAfterTimestamp') ?? strtotime('-24 hours');

        if (!$userGuid) {
            $this->out('User guid required');
            return;
        }

        if ($tenantId) {
            $this->multiTenantBootService->bootFromTenantId($tenantId);
        }

        /** @var User */
        $user = $this->entitiesBuilder->single($userGuid);

        if (!$user || !($user instanceof User)) {
            $this->out('User not found');
            return;
        }

        $email = $this->unreadMessages->setUser($user)
            ->setCreatedAfterTimestamp($createdAfterTimestamp);
        
        if (!$email) {
            $this->out('Unable to generate email.');
            return;
        }

        if ($output) {
            file_put_contents($output, $email->build()->buildHtml());
            $this->out("Generated email for " . $user->getGuid());
        } else {
            $email->send($user);
            $this->out("Sent email to " . $user->getGuid());
        }
    }

    /**
     * Bulk send unread message emails to all users who should recieve one, across all tenants.
     * @param string $createdAfterTimestamp - how recent must messages be to be included? (optional - defaults to 6 hours ago).
     * @param bool $includeMinds - should the main Minds network be included? (optional - defaults to true).
     * @example
     * - php cli.php Email bulkSendUnreadMessageEmailAcrossTenants --createdAfterTimestamp=1721814873
     * @return void
     */
    public function bulkSendUnreadMessageEmailAcrossTenants(): void
    {
        $createdAfterTimestamp = $this->getOpt('createdAfterTimestamp') ?? strtotime('-6 hours');
        $includeMinds = $this->getOpt('includeMinds') !== 'false' ?? true;

        if ($includeMinds) {
            try {
                $this->unreadMessagesDispatcher->dispatchForTenant(-1, $createdAfterTimestamp);
            } catch (\Exception $e) {
                $this->out("Error sending for tenant_id: -1");
                $this->out($e->getMessage());
            }
        }

        foreach ($this->multiTenantDataService->getTenants(limit: 9999999) as $tenant) {
            try {
                $this->unreadMessagesDispatcher->dispatchForTenant($tenant->id, $createdAfterTimestamp);
            } catch (\Exception $e) {
                $this->out("Error sending for tenant_id: $tenant->id");
                $this->out($e->getMessage());
            }
        }
    }

    /**
     * Bulk send unread message emails to all users who should recieve one, of a specific tenant.
     * @param string $createdAfterTimestamp - how recent must messages be to be included? (optional - defaults to 6 hours ago).
     * @param int $tenantId - tenant id of the given network.
     * @example
     * - php cli.php Email bulkSendUnreadMessageEmailToTenant --tenantId=-1 --createdAfterTimestamp=1721814873
     * @return void
     */
    public function bulkSendUnreadMessageEmailToTenant(): void
    {
        $createdAfterTimestamp = $this->getOpt('createdAfterTimestamp') ?? strtotime('-6 hours');
        $tenantId = $this->getOpt('tenantId');

        if (!$tenantId) {
            $this->out('Tenant id required');
            return;
        }

        $this->unreadMessagesDispatcher->dispatchForTenant($tenantId, $createdAfterTimestamp);
    }

    public function testDigest()
    {
        $userGuid = $this->getOpt('userGuid');
        $tenantId = $this->getOpt('tenantId');
        $output = $this->getOpt('output');

        if ($tenantId) {
            $this->multiTenantBootService->bootFromTenantId($tenantId);
        }

        /** @var User */
        $user = $this->entitiesBuilder->single($userGuid);

        if ($output) {
            $message = (new Digest())->setUser($user)->build();

            if (!$message) {
                $this->out('Unable to generate email.');
                return;
            }

            file_put_contents($output, $message->buildHtml());
        } else {
            $digest = new DigestSender();
            $digest->send($user);
        }

        $this->out('Sent');
    }

    public function tenantsSendDigests()
    {
        $this->tenantEmailService->sendToAllUsersAcrossTenants(new DigestSender());
    }

    /**
     * Test start networks tenant trial email.
     * @example
     * - php cli.php Email testTenantTrial --email="noreply@minds.com"
     * @return void
     */
    public function testTenantTrial(): void
    {
        $email = $this->getOpt('email') ?? null;

        if (!$email) {
            throw new CliException('Email required');
        }

        Di::_()->get(AutoTrialService::class)
            ->startTrialWithEmail($email);
    }

    public function testPlusTrial()
    {
        $userGuid = $this->getOpt('userGuid');
        $user = new User($userGuid);

        $subscription = new Core\Payments\Subscriptions\Subscription();
        $subscription
            ->setTrialDays(7)
            ->setUser($user)
            ->setEntity(new User(730071191229833224))
            ->setNextBilling(strtotime('+7 days'));

        $emailDelegate = new Core\Payments\Subscriptions\Delegates\EmailDelegate();
        $emailDelegate->onCreate($subscription);

        $this->out('End.');
    }

    /**
     * Example usage:
     * php cli.php Email testSupermind --topic='supermind_request_sent' --output=/var/www/Minds/engine/supermind.html --senderGuid='1215744293826727938' --receiverGuid='1107439332647505934' --activityGuid='1416522245517348865' --paymentMethod=1 --paymentAmount=12.345
     *
     * Payment method can be 0 (cash) or 1 (off-chain tokens)
     * See the Supermind emailer for topics
     */
    public function testSupermind()
    {
        $output = $this->getOpt('output');
        $topic = $this->getOpt('topic');
        $senderGuid = $this->getOpt('senderGuid');
        $receiverGuid = $this->getOpt('receiverGuid');
        $activityGuid = $this->getOpt('activityGuid');
        $paymentMethod = $this->getOpt('paymentMethod');
        $paymentAmount = $this->getOpt('paymentAmount');

        $supermindRequest = new Supermind\Models\SupermindRequest();

        $supermindRequest->setActivityGuid($activityGuid)
            ->setSenderGuid($senderGuid)
            ->setReceiverGuid($receiverGuid)
            ->setPaymentMethod($paymentMethod)
            ->setPaymentAmount($paymentAmount);

        $supermindEmailer = new SupermindEmail();
        $supermindEmailer->setTopic($topic)
            ->setSupermindRequest($supermindRequest);

        if ($output) {
            $message = $supermindEmailer->build();
            file_put_contents($output, $message->buildHtml());
        }
    }

    public function sync_sendgrid_lists(): void
    {
        Di::_()->get('Config')->set('min_log_level', 'INFO');

        $sendGridManager = Di::_()->get('SendGrid\Manager');
        $sendGridManager->syncContactLists();
    }

    public function sync_marketing_attributes()
    {
        ini_set('memory_limit', '2G');
        Di::_()->get('Config')->set('min_log_level', 'INFO');

        $mautic = new Core\Email\Mautic\MarketingAttributes\Manager();
        $mautic->sync();
    }

    public function sync_mautic()
    {
        Di::_()->get('Config')->set('min_log_level', 'INFO');

        $fromTs = null;
        $offset = $this->getOpt('offset') ?: 0;

        if ($fromDate = $this->getOpt('from-timestamp')) {
            $fromTs = strtotime($fromDate);
        }

        if ($hoursAgo = $this->getOpt('hours-ago')) {
            $fromTs = strtotime("$hoursAgo hours ago");
        }

        $mautic = Di::_()->get(Core\Email\Mautic\Manager::class);
        $mautic->sync(fromTs: $fromTs, offset: $offset);
    }

    public function sendInvites(): void
    {
        (function () {
            /**
             * @var InviteSenderService $invitesService
             */
            $invitesService = Di::_()->get(InviteSenderService::class);
            $invitesService->sendInvites();
        })();
    }

    public function testPasswordReset()
    {
        $userGuid = $this->getOpt('userGuid');
        $output = $this->getOpt('output');

        $user = Di::_()->get(EntitiesBuilder::class)->single($userGuid);
        $ignore = ACL::$ignore;
        ACL::$ignore = true;

        $code = Password::reset($user);

        $campaign = (new ForgotPasswordEmailer());
        $campaign
            ->setUser($user)
            ->setCode($code);

        $message = $campaign->build();

        ACL::$ignore = $ignore;

        if ($output) {
            file_put_contents($output, $message->buildHtml());
        } else {
            $this->out($message->buildHtml());
        }
    }

    /**
     * Tests tenant welcome email.
     * Example usage:
     * ```sh
     * php cli.php Email testTenantWelcomeEmail --output=/var/www/Minds/engine/welcome.html --userGuid="1540482017907445761" --tenantId=123
     * ```
     * @return void
     */
    public function testTenantWelcomeEmail()
    {
        $userGuid = $this->getOpt('userGuid');
        $tenantId = $this->getOpt('tenantId');
        $output = $this->getOpt('output');

        if (!$tenantId) {
            $this->out('Tenant id required');
            return;
        }

        if (!$userGuid) {
            $this->out('User guid required');
            return;
        }

        Di::_()->get(MultiTenantBootService::class)->bootFromTenantId($tenantId);
        $user = Di::_()->get(EntitiesBuilder::class)->single($userGuid);

        if (!$user || !($user instanceof User)) {
            $this->out('User not found');
            return;
        }

        $campaign = Di::_()->get(TenantUserWelcomeEmailer::class);
        $campaign->setUser($user);

        $message = $campaign->build();

        if ($output) {
            file_put_contents($output, $message->buildHtml());
        } else {
            $campaign->queue();
            $this->out('Tried to send email - printing HTML:');
            $this->out($message->buildHtml());
        }
    }
}
