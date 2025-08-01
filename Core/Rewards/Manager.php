<?php
/**
 * Syncs a users contributions to rewards values
 */
namespace Minds\Core\Rewards;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Minds\Common\Repository\Response;
use Minds\Core\Analytics\UserStates\UserActivityBuckets;
use Minds\Core\Blockchain\Transactions\Repository as TxRepository;
use Minds\Core\Blockchain\Wallets\OffChain\Transactions;
use Minds\Core\Blockchain\Transactions\Transaction;
use Minds\Core\Blockchain\LiquidityPositions;
use Minds\Core\Blockchain\Services\BlockFinder;
use Minds\Core\Blockchain\Token;
use Minds\Core\Blockchain\Util;
use Minds\Core\Blockchain\Wallets\OnChain\UniqueOnChain;
use Minds\Core\Blockchain\Wallets\OnChain\UniqueOnChain\UniqueOnChainAddress;
use Minds\Core\Di\Di;
use Minds\Entities\User;
use Minds\Core\Guid;
use Minds\Core\Rewards\Contributions\ContributionQueryOpts;
use Minds\Core\Util\BigNumber;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Rewards\TokenomicsManifests\TokenomicsManifestInterface;
use Minds\Core\Rewards\TokenomicsManifests\TokenomicsManifestV2;
use Minds\Core\Log\Logger;
use Minds\Helpers\Flags;

class Manager
{
    /** @var string[] */
    const REWARD_TYPES = [
        self::REWARD_TYPE_ENGAGEMENT,
        self::REWARD_TYPE_LIQUIDITY,
        self::REWARD_TYPE_HOLDING,
    ];

    /** @var string */
    const REWARD_TYPE_ENGAGEMENT = 'engagement';

    /** @var string */
    const REWARD_TYPE_LIQUIDITY = 'liquidity';

    /** @var string */
    const REWARD_TYPE_HOLDING = 'holding';

    /** @var int */
    const REWARD_MULTIPLIER = 3;

    /** @var Contributions\Manager */
    protected $contributions;

    /** @var Transactions */
    protected $transactions;

    /** @var TxRepository */
    protected $txRepository;

    /** @var Ethereum */
    protected $eth;

    /** @var Config */
    protected $config;

    /** @var Repository */
    protected $repository;

    /** @var EntitiesBuilder */
    protected $entitiesBuilder;

    /** @var LiquidityPositions\Manager */
    protected $liquidityPositionsManager;

    /** @var UniqueOnChain\Manager */
    protected $uniqueOnChainManager;

    /** @var BlockFinder */
    protected $blockFinder;

    /** @var Token */
    protected $token;

    /** @var Logger */
    protected $logger;

    /** @var User $user */
    protected $user;

    /** @var int $from */
    protected $from;

    /** @var int $to */
    protected $to;

    /** @var bool $dryRun */
    protected $dryRun = false;

    public function __construct(
        $contributions = null,
        $transactions = null,
        $txRepository = null,
        $eth = null,
        $config = null,
        $repository = null,
        $entitiesBuilder = null,
        $liquidityPositionManager = null,
        $uniqueOnChainManager = null,
        $blockFinder = null,
        $token = null,
        $logger = null
    ) {
        $this->contributions = $contributions ?: new Contributions\Manager;
        $this->transactions = $transactions ?: Di::_()->get('Blockchain\Wallets\OffChain\Transactions');
        $this->txRepository = $txRepository ?: Di::_()->get('Blockchain\Transactions\Repository');
        $this->eth = $eth ?: Di::_()->get('Blockchain\Services\Ethereum');
        $this->config = $config ?: Di::_()->get('Config');
        $this->repository = $repository ?? new Repository();
        $this->entitiesBuilder = $entitiesBuilder ?? Di::_()->get('EntitiesBuilder');
        $this->liquidityPositionsManager = $liquidityPositionManager ?? Di::_()->get('Blockchain\LiquidityPositions\Manager');
        $this->uniqueOnChainManager = $uniqueOnChainManager ?? Di::_()->get('Blockchain\Wallets\OnChain\UniqueOnChain\Manager');
        $this->blockFinder = $blockFinder ?? Di::_()->get('Blockchain\Services\BlockFinder');
        $this->token = $token ?? Di::_()->get('Blockchain\Token');
        $this->logger = $logger ?? Di::_()->get('Logger');
        $this->from = strtotime('-7 days') * 1000;
        $this->to = time() * 1000;
    }

    /**
     * @param User $user
     * @return Manager
     */
    public function setUser($user): Manager
    {
        $manager = clone $this;
        $manager->user = $user;
        return $manager;
    }

    /**
     * Does not issue tokens!
     * @param RewardEntry $rewardEntry
     * @return bool
     */
    public function add(RewardEntry $rewardEntry): bool
    {
        //
        return $this->repository->add($rewardEntry);
    }

    /**
     * @param RewardsQueryOpts $opts (optional)
     * @return Response
     */
    public function getList(RewardsQueryOpts $opts = null): Response
    {
        return $this->repository->getList($opts);
    }

    /**
     * @param RewardsQueryOpts $opts (optional)
     * @return RewardsSummary
     */
    public function getSummary(RewardsQueryOpts $opts = null): RewardsSummary
    {
        $rewardEntries = $this->getList($opts);

        $rewardsSummary = new RewardsSummary();
        $rewardsSummary->setUserGuid($opts->getUserGuid())
            ->setDateTs($opts->getDateTs())
            ->setRewardEntries($rewardEntries->toArray());

        return $rewardsSummary;
    }


    /**
     * @return void
     */
    public function calculate(RewardsQueryOpts $opts = null): void
    {
        $opts = $opts ?? (new RewardsQueryOpts())
            ->setDateTs(time());

        ////
        // First, work out our scores
        ////

        // Engagement rewards

        $contributionsOpts = (new ContributionQueryOpts())
           ->setDateTs(strtotime('midnight', $opts->getDateTs()));

        foreach ($this->contributions->getSummaries($contributionsOpts) as $i => $contributionSummary) {
            /** @var User */
            $user = $this->entitiesBuilder->single($contributionSummary->getUserGuid());
            if (!$user) {
                continue;
            }

            // Require phone number to be setup for uniqueness
            if (!$user->getPhoneNumberHash()) {
                continue;
            }

            $score = BigDecimal::of($contributionSummary->getScore())->multipliedBy(self::REWARD_MULTIPLIER);

            $rewardEntry = new RewardEntry();
            $rewardEntry->setUserGuid($contributionSummary->getUserGuid())
                ->setDateTs($contributionSummary->getDateTs())
                ->setRewardType(static::REWARD_TYPE_ENGAGEMENT)
                ->setScore($score)
                ->setMultiplier(BigDecimal::of(self::REWARD_MULTIPLIER));

            //

            $this->add($rewardEntry);

            $this->logger->info("[$i]: Engagement score calculated as $score", [
                'userGuid' => $rewardEntry->getUserGuid(),
                'reward_type' => $rewardEntry->getRewardType(),
            ]);
        }

        // Liquidity rewards

        $liquidityScores = [];
        try {
            foreach ([Util::BASE_CHAIN_ID, Util::ETHEREUM_CHAIN_ID] as $chainId) {
                foreach ($this->liquidityPositionsManager
                            ->setDateTs($opts->getDateTs())
                            ->setChainId($chainId)
                            ->getAllProvidersSummaries() as $i => $liquiditySummary
                ) {
                    
                    if (!isset($liquidityScores[$liquiditySummary->getUserGuid()])) {
                        $liquidityScores[$liquiditySummary->getUserGuid()] = BigDecimal::of(0);
                    }

                    $liquidityScores[$liquiditySummary->getUserGuid()] = $liquidityScores[$liquiditySummary->getUserGuid()]->plus($liquiditySummary->getLpPosition());
                }
            }

            $i = 0;
            foreach ($liquidityScores as $userGuid => $score) {
                ++$i;
                $rewardEntry = new RewardEntry();
                $rewardEntry->setUserGuid($userGuid)
                    ->setDateTs($opts->getDateTs())
                    ->setRewardType(static::REWARD_TYPE_LIQUIDITY);
    
                $multiplier = BigDecimal::of(self::REWARD_MULTIPLIER);
                
                $score = $score->multipliedBy($multiplier);
                
                // Update our new RewardEntry
                $rewardEntry
                    ->setScore($score)
                    ->setMultiplier($multiplier);
    
                $this->add($rewardEntry);
    
                $this->logger->info("[$i]: Liquidity score calculated as $score", [
                    'userGuid' => $rewardEntry->getUserGuid(),
                    'reward_type' => $rewardEntry->getRewardType(),
                    'multiplier' => (string) $multiplier,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        // Holding rewards

        foreach ($this->uniqueOnChainManager->getAll() as $i => $uniqueOnChain) {
            /** @var User */
            $user = $this->entitiesBuilder->single($uniqueOnChain->getUserGuid());
            if (!$user || !$user instanceof User) {
                continue;
            }

            // Require phone number to be setup for uniqueness
            if (!$user->getPhoneNumberHash()) {
                continue;
            }

            if (strtolower($uniqueOnChain->getAddress()) !== strtolower($user->getEthWallet())) {
                continue;
            }

            // Return combined chains token balance
            $tokenBalance = BigDecimal::of(0);
            foreach ([Util::BASE_CHAIN_ID, Util::ETHEREUM_CHAIN_ID] as $chainId) {
                $blockNumber = $this->blockFinder->getBlockByTimestamp($opts->getDateTs(), $chainId);
                $tokenBalance = $tokenBalance->plus($this->getTokenBalance($uniqueOnChain, $blockNumber, $chainId));
            }

            $rewardEntry = new RewardEntry();
            $rewardEntry->setUserGuid($user->getGuid())
                ->setDateTs($opts->getDateTs())
                ->setRewardType(static::REWARD_TYPE_HOLDING);

            $multiplier = BigDecimal::of(self::REWARD_MULTIPLIER);
            $score = BigDecimal::of($tokenBalance)->multipliedBy($multiplier);

            // Update our new RewardEntry
            $rewardEntry
                ->setScore($score)
                ->setMultiplier($multiplier);

            $this->add($rewardEntry);

            $this->logger->info("[$i]: Holding score calculated as $score", [
                'userGuid' => $rewardEntry->getUserGuid(),
                'reward_type' => $rewardEntry->getRewardType(),
                'blockNumber' => $blockNumber,
                'tokenBalance' => $tokenBalance,
                'multiplier' => (string) $multiplier,
            ]);
        }
        

        //

        ////
        // Then, work out the tokens based off our score / globalScore
        ////

        foreach ($this->repository->getIterator($opts) as $i => $rewardEntry) {
            // Confirm the wallet address is still connected
            if (in_array($rewardEntry->getRewardType(), [static::REWARD_TYPE_LIQUIDITY, static::REWARD_TYPE_HOLDING], false)) {
                /** @var User */
                $user = $this->entitiesBuilder->single($rewardEntry->getUserGuid());
                if (!$user || !$this->uniqueOnChainManager->isUnique($user) || Flags::shouldFail($user)) {
                    // do not issue payout

                    $rewardEntry->setScore(BigDecimal::of(0));
                    $rewardEntry->setTokenAmount(BigDecimal::of(0));
                    $this->repository->update($rewardEntry, [ 'token_amount', 'score' ]);

                    $this->logger->info("[$i]: Clearing score and token amount for {$rewardEntry->getUserGuid()}. Address isn't unique or user is not valid.", [
                        'userGuid' => $rewardEntry->getUserGuid(),
                        'reward_type' => $rewardEntry->getRewardType(),
                    ]);
                    continue;
                }
            }

            // Get the pool
            $tokenomicsManifest = $this->getTokenomicsManifest($rewardEntry->getTokenomicsVersion());
            $tokenPool = BigDecimal::of($tokenomicsManifest->getDailyPools()[$rewardEntry->getRewardType()]);

            if ($rewardEntry->getSharePct() === (float) 0) {
                $tokenAmount = BigDecimal::of(0); // If share % 0 then reset token amount
            } else {
                $tokenAmount = $tokenPool->multipliedBy($rewardEntry->getSharePct(), 18, RoundingMode::FLOOR);
            }

            // Do not allow negative rewards to be issued
            if ($tokenAmount->isLessThanOrEqualTo(0)) {
                $tokenAmount = BigDecimal::of(0);
            }

            $rewardEntry->setTokenAmount($tokenAmount);
            $this->repository->update($rewardEntry, [ 'token_amount' ]);

            $sharePct = $rewardEntry->getSharePct() * 100;

            $this->logger->info("[$i]: Issued $tokenAmount tokens ($sharePct%)", [
                'userGuid' => $rewardEntry->getUserGuid(),
                'reward_type' => $rewardEntry->getRewardType(),
            ]);
        }
    }

    /**
     * Calculate today's reward multiplier by adding daily increment to yesterday's multiplier
     * @return float
     * @param RewardEntry $rewardEntry
     */
    public function calculateMultiplier(RewardEntry $rewardEntry): BigDecimal
    {
        $manifest = $this->getTokenomicsManifest($rewardEntry->getTokenomicsVersion());

        $maxMultiplierDays = $manifest->getMaxMultiplierDays(); // 365
        $multiplierRange = $manifest->getMaxMultiplier() - $manifest->getMinMultiplier();

        $dailyIncrement = BigDecimal::of($multiplierRange)->dividedBy($maxMultiplierDays, 12, RoundingMode::CEILING); // 0.0054794520

        $multiplier = $rewardEntry->getMultiplier()->plus($dailyIncrement);

        return BigDecimal::min($multiplier, $manifest->getMaxMultiplier());
    }

    /**
     * Issue tokens based on alreay calculated RewardEntry's
     * @param bool $dryRun
     * @return void
     */
    public function issueTokens(RewardsQueryOpts $opts = null, $dryRun = true): void
    {
        $opts = $opts ?? (new RewardsQueryOpts())
            ->setDateTs(strtotime('yesterday'));

        foreach ($this->repository->getIterator($opts) as $i => $rewardEntry) {
            if ($rewardEntry->getTokenAmount()->toFloat() === (float) 0) {
                continue;
            }
            
            // Do not payout again if we have already issued a payout
            if ($rewardEntry->getPayoutTx()) {
                continue;
            }

            $tokenAmount = (string) BigNumber::toPlain($rewardEntry->getTokenAmount(), 18);

            $transaction = new Transaction();
            $transaction
                ->setUserGuid($rewardEntry->getUserGuid())
                ->setWalletAddress('offchain')
                ->setTimestamp(strtotime("+24 hours - 1 second", $rewardEntry->getDateTs()))
                ->setTx('oc:' . Guid::build())
                ->setAmount($tokenAmount)
                ->setContract('offchain:reward')
                ->setData([
                    'reward_type' => $rewardEntry->getRewardType(),
                ])
                ->setCompleted(true);

            if (!$dryRun) {
                $this->txRepository->add($transaction);

                // Add in the TX to the database for auditing
                $rewardEntry->setPayoutTx($transaction->getTx());
                $this->repository->update($rewardEntry, [ 'payout_tx' ]);
            }

            $this->logger->info("[$i]: Issued $tokenAmount tokens", [
                'userGuid' => $rewardEntry->getUserGuid(),
                'reward_type' => $rewardEntry->getRewardType(),
                'tx' => $transaction->getTx(),
            ]);
        }
    }

    /**
     * @param int $tokenomicsVersion
     * @return TokenomicsManifestInterface
     */
    private function getTokenomicsManifest($tokenomicsVersion = 1): TokenomicsManifestInterface
    {
        switch ($tokenomicsVersion) {
            case 2:
                return new TokenomicsManifestV2();
                break;
            default:
                throw new \Exception("Invalid tokenomics version");
        }
    }

    /**
     * Gets token balance if one is not already set.
     * @param UniqueOnChainAddress $uniqueOnChainAddress - unique onchain address object.
     * @param integer $blockNumber - block number to check for.
     * @param integer $chainId - the chain to get the balance from
     * @return string - balance as string.
     */
    private function getTokenBalance(UniqueOnChainAddress $uniqueOnChainAddress, int $blockNumber, int $chainId): string
    {
        return $this->token->fromTokenUnit(
            $this->token->balanceOf(
                account: $uniqueOnChainAddress->getAddress(),
                // Historicals aren't working with ETH so just use the latest block
                blockNumber: $chainId === Util::ETHEREUM_CHAIN_ID ? null : $blockNumber,
                chainId: $chainId
            )
        );
    }

    public function setFrom($argument1)
    {
        // TODO: write logic here
    }
}
