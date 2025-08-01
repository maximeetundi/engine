<?php

namespace Minds\Core\Comments;

use Minds\Core\Di\Di;
use Minds\Core\Events\Dispatcher;
use Minds\Core\Guid;
use Minds\Core\Luid;
use Minds\Core\Security\ACL;
use Minds\Entities\EntityInterface;
use Minds\Entities\Enums\FederatedEntitySourcesEnum;
use Minds\Entities\FederatedEntityInterface;
use Minds\Entities\RepositoryEntity;
use Minds\Entities\User;
use Minds\Exceptions\ServerErrorException;
use Minds\Helpers\Export;
use Minds\Helpers\Flags;
use Minds\Helpers\Unknown;

/**
 * Comment Entity
 * @package Minds\Core\Comments
 * @method Comment setEntityGuid(int $value)
 * @method Comment setParentGuidL1(int $value)
 * @method int getParentGuidL1()
 * @method Comment setParentGuidL2(int $value)
 * @method int getParentGuidL2()
 * @method Comment setParentGuidL3(int $value)
 * @method int getParentGuidL3()
 * @method Comment setParentGuid(int $parentGuid)
 * @method Comment setGuid(int $value)
 * @method Comment setRepliesCount(int $value)
 * @method int getRepliesCount())
 * @method Comment setOwnerGuid(int $value)
 * @method Comment setTimeCreated(int $value)
 * @method int getTimeCreated()
 * @method Comment setTimeUpdated(int $value)
 * @method int getTimeUpdated()
 * @method Comment setBody(string $value)
 * @method Comment setAttachments(array $value)
 * @method array getAttachments()
 * @method Comment setMature(bool $value)
 * @method bool isMature()
 * @method Comment setEdited(bool $value)
 * @method bool isEdited()
 * @method Comment setSpam(bool $value)
 * @method bool isSpam()
 * @method Comment setDeleted(bool $value)
 * @method bool isDeleted()
 * @method Comment setVotesUp(array $value)
 * @method array getVotesUp()
 * @method Comment setVotesDown(array $value)
 * @method array getVotesDown()
 * @method Comment setEphemeral(bool $value)
 * @method bool isEphemeral()
 * @method Comment setGroupConversation(bool $value)
 * @method bool isGroupConversation()
 * @method Comment setPinned(bool $value)
 * @method bool isPinned()
 */
class Comment extends RepositoryEntity implements EntityInterface, FederatedEntityInterface
{
    /** @var string */
    protected $type = 'comment';

    /** @var int */
    protected $entityGuid;

    /** @var int */
    protected $parentGuidL1;

    /** @var int */
    protected $parentGuidL2;

    /** @var int */
    protected $parentGuidL3 = 0; // Not supported yet

    /** @var int */
    protected $parentGuid;

    /** @var int */
    protected $guid;

    /** @var int */
    protected $repliesCount = 0;

    /** @var int */
    protected $ownerGuid;

    /** @var int */
    protected $containerGuid;

    /** @var int */
    protected $timeCreated;

    /** @var int */
    protected $timeUpdated;

    /** @var string */
    protected $body;

    /** @var array */
    protected $attachments = [];

    /** @var bool */
    protected $mature = false;

    /** @var bool */
    protected $edited = false;

    /** @var bool */
    protected $spam = false;

    /** @var bool */
    protected $deleted = false;

    /** @var FederatedEntitySourcesEnum */
    protected $source = FederatedEntitySourcesEnum::LOCAL;

    /** @var string */
    protected $canonicalUrl;

    /** @var array */
    protected $ownerObj;

    /** @var array */
    protected $votesUp;

    /** @var array */
    protected $votesDown;

    /** @var bool */
    protected $groupConversation = false;

    /** @var bool */
    protected $ephemeral = true;

    /** @var bool */
    protected $pinned = false;

    private array $clientMeta = [];

    /**
     * Gets the entity guid for the comment.
     * !!!NOTE!!! Needed for 'create' event hook
     * @return int
     */
    public function getEntityGuid()
    {
        return $this->entityGuid;
    }

    /**
     * @return Luid
     * @throws \Minds\Exceptions\InvalidLuidException
     */
    public function getLuid()
    {
        $luid = new Luid();

        $luid
            ->setType('comment')
            ->setEntityGuid((string) $this->getEntityGuid())
            ->setPartitionPath($this->getPartitionPath())
            ->setParentPath($this->getParentPath())
            ->setChildPath($this->getChildPath())
            ->setGuid((string) $this->getGuid());

        return $luid;
    }

    /**
     * @return int
     */
    public function getGuid(): string
    {
        if (!$this->guid) {
            $this->setGuid(Guid::build());
        }

        return (string) $this->guid;
    }

    /**
     * @param array|string $value
     * @return $this
     */
    public function setOwnerObj($value)
    {
        if (is_string($value) && $value) {
            $value = json_decode($value, true);
        } elseif ($value instanceof User) {
            $value = $value->export();
        }

        $this->ownerObj = $value;
        $this->markAsDirty('ownerObj');

        if ($value && !$this->ownerGuid) {
            $this->ownerGuid = $value['guid'];
            $this->markAsDirty('ownerGuid');
        }

        return $this;
    }

    /**
     * Gets (hydrates if necessary) the owner object
     * @return array
     * @throws \Exception
     */
    public function getOwnerObj()
    {
        if (!$this->ownerObj && $this->ownerGuid) {
            $user = Di::_()->get('EntitiesBuilder')->single($this->ownerGuid, [ 'cacheTtl' => 84600 ]);
            $user->fullExport = false;
            $this->setOwnerObj($user->export());
        }

        return $this->ownerObj;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        if (mb_strlen($this->body) > 1500) {
            return mb_substr($this->body, 0, 1500) . '...';
        }
        return $this->body;
    }

    /**
     * Sets an individual attachment
     * @param $attachment
     * @param mixed $value
     * @return Comment
     */
    public function setAttachment($attachment, $value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        $this->attachments[$attachment] = (string) $value;
        $this->markAsDirty('attachments');

        return $this;
    }

    /**
     * Remove all attachments
     * @return Comment
     */
    public function removeAttachments(): self
    {
        $this->attachments = [];
        $this->markAsDirty('attachments');

        return $this;
    }

    /**
     * Gets an individual attachment's value
     * @param $attachment
     * @return bool
     */
    public function getAttachment($attachment)
    {
        if (!isset($this->attachments[$attachment])) {
            return false;
        }

        if (in_array(substr($this->attachments[$attachment], 0, 1), ['[', '{'], true)) {
            return json_decode($this->attachments[$attachment], true);
        }

        return $this->attachments[$attachment];
    }

    /**
     * Returns if the entity can be edited
     * @param User|null $user
     * @return bool
     */
    public function canEdit(User $user = null)
    {
        return ACL::_()->write($this, $user);
    }

    /**
     * Get exact path, includes all the partition
     * @return string
     */
    public function getPartitionPath()
    {
        return "{$this->getParentGuidL1()}:{$this->getParentGuidL2()}:{$this->getParentGuidL3()}";
    }

    /**
     * Return the partition path of the parent
     * that can be used to grab the parent thread
     * @return string
     */
    public function getParentPath()
    {
        if ($this->getParentGuidL2() == 0) {
            return "0:0:0";
        }
        return "{$this->getParentGuidL1()}:0:0";
    }

    /**
     * Return the partition path to be used to
     * fetch child replies
     */
    public function getChildPath()
    {
        if ($this->getParentGuidL1() == 0) { //No parent so we are at the top level
            return "{$this->getGuid()}:0:0";
        }
        if ($this->getParentGuidL2() == 0) { //No level2 so we are at the l1 level
            return "{$this->getParentGuidL1()}:{$this->getGuid()}:0";
        }
        return "{$this->getParentGuidL1()}:{$this->getParentGuidL2()}:{$this->getGuid()}";
    }

    /**
     * Returns the guid of the parent
     * If this is a top level comment, null will be returned
     */
    public function getParentGuid(): ?int
    {
        if (isset($this->parentGuid)) {
            return $this->parentGuid;
        }
        if ($this->getParentGuidL2()) {
            return $this->getParentGuidL2();
        }
        if ($this->getParentGuidL1()) {
            return $this->getParentGuidL1();
        }
        return null;
    }

    /**
     * Return an array of thumbnails
     * @return array
     */
    public function getThumbnails(): array
    {
        $thumbnails = [];
        $mediaManager = Di::_()->get('Media\Image\Manager');
        $sizes = [ 'xlarge', 'large' ];
        foreach ($sizes as $size) {
            $thumbnails[$size] = $mediaManager->getPublicAssetUris($this, $size)[0];
        }
        return $thumbnails;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return 'comment';
    }

    /**
     * @return string
     */
    public function getSubtype(): ?string
    {
        return null;
    }

    /**
     * Return the urn for the comment
     * @return string
     */
    public function getUrn(): string
    {
        return implode(':', [
            'urn',
            'comment',
            $this->getEntityGuid(),
            $this->getPartitionPath(),
            $this->getGuid(),
        ]);
    }

    /**
     * Returns the urn of the parent comment
     */
    public function getParentUrn(): string
    {
        if (!$this->getParentGuid()) {
            throw new ServerErrorException("You can not request a parentUrn if the is no parent comment");
        }

        return implode(':', [
            'urn',
            'comment',
            $this->getEntityGuid(),
            $this->getParentPath(),
            $this->getParentGuid(),
        ]);
    }

    public function getOwnerGuid(): string
    {
        return (string) $this->ownerGuid;
    }

    /**
     * Return the entity guid
     * @return string
     */
    public function getAccessId(): string
    {
        return $this->getEntityGuid();
    }

    /**
     * Comments don't have containers
     * @return null
     */
    public function getContainerEntity()
    {
        return null;
    }

    public function setClientMeta(array $clientMeta): self
    {
        $this->clientMeta = $clientMeta;
        return $this;
    }

    public function getClientMeta(): array
    {
        return $this->clientMeta;
    }

    /**
     * Defines the exportable members
     * @return array
     */
    public function getExportable()
    {
        return [
            'type',
            'entityGuid',
            'parentGuidL1',
            'parentGuidL2',
            'guid',
            'repliesCount',
            'ownerGuid',
            'timeCreated',
            'timeUpdated',
            'body',
            'attachments',
            'mature',
            'edited',
            'spam',
            'deleted',
            'canonicalUrl',
            'source',
            'pinned',
            function ($export) {
                return $this->_extendExport($export);
            }
        ];
    }

    /**
     * @param array $export
     * @return array
     * @throws \Minds\Exceptions\InvalidLuidException
     */
    protected function _extendExport(array $export)
    {
        $output = [];

        $output['urn'] = $this->getUrn();
        $output['_guid'] = (string) $export['guid'];
        $output['guid'] = $output['luid'] = (string) $this->getLuid();

        $output['entity_guid'] = (string) $this->getEntityGuid();

        $output['parent_guid_l1'] = (string) $this->getParentGuidL1();
        $output['parent_guid_l2'] = (string) $this->getParentGuidL2();

        $output['partition_path'] = $this->getPartitionPath();
        $output['parent_path'] = $this->getParentPath();
        $output['child_path'] = $this->getChildPath();

        // Legacy
        $output['ownerObj'] = $this->getOwnerObj();
        $output['description'] = $this->getBody();

        if (!$output['ownerObj'] && !$this->getOwnerGuid()) {
            $unknown = Unknown::user();

            $output['ownerObj'] = $unknown->export();
            $output['owner_guid'] = $unknown->guid;
        }

        $output['thumbnails'] = $this->getThumbnails();

        $output['can_reply'] = (bool) !$this->getParentGuidL2();

        //$output['parent_guid'] = (string) $this->entityGuid;

        $output = array_merge($output, Dispatcher::trigger(
            event: 'export:extender',
            namespace: 'comment',
            params: [ 'entity' => $this ],
            default_return: []
        ));

        // Patch
        $output['link_title'] = $output['title'] ?? '';

        if (!Flags::shouldDiscloseStatus($this)) {
            unset($output['spam']);
            unset($output['deleted']);
        }

        $output = Export::sanitize($output);

        return $output;
    }

    /**
     * @inheritDoc
     */
    public function setSource(FederatedEntitySourcesEnum $source): FederatedEntityInterface
    {
        $this->source = $source;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getSource(): ?FederatedEntitySourcesEnum
    {
        return $this->source;
    }

    /**
     * @inheritDoc
     */
    public function setCanonicalUrl(string $canonicalUrl): FederatedEntityInterface
    {
        $this->canonicalUrl = $canonicalUrl;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCanonicalUrl(): ?string
    {
        return isset($this->canonicalUrl) ? $this->canonicalUrl : null;
    }
}
