<?php

namespace App\Console\Commands;

use App\ActiveCardHolderUpdate;
use App\Google\GoogleApi;
use App\PaypalBasedMember;
use App\Slack\SlackApi;
use App\WooCommerce\Api\ApiCallFailed;
use App\WooCommerce\Api\WooCommerceApi;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

class IdentifyIssues extends Command
{
    const ISSUE_WITH_A_CARD = "Issue with a card";
    const ISSUE_SLACK_ACCOUNT = "Issue with a Slack account";
    const ISSUE_GOOGLE_GROUPS = "Issue with google groups";

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'denhac:identify-issues';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identifies issues with membership and access';

    /**
     * @var MessageBag
     */
    private $issues;
    /**
     * @var WooCommerceApi
     */
    private $wooCommerceApi;
    /**
     * @var SlackApi
     */
    private $slackApi;
    /**
     * @var GoogleApi
     */
    private $googleApi;

    /**
     * Create a new command instance.
     *
     * @param WooCommerceApi $wooCommerceApi
     * @param SlackApi $slackApi
     * @param GoogleApi $googleApi
     */
    public function __construct(WooCommerceApi $wooCommerceApi,
                                SlackApi $slackApi,
                                GoogleApi $googleApi)
    {
        parent::__construct();

        $this->issues = new MessageBag();
        $this->wooCommerceApi = $wooCommerceApi;
        $this->slackApi = $slackApi;
        $this->googleApi = $googleApi;
    }

    /**
     * Execute the console command.
     *
     * @throws ApiCallFailed
     */
    public function handle()
    {
        $this->info("Identifying issues");
        $members = $this->getMembers();
        $this->unknownActiveCard($members);
        $this->extraSlackUsers($members);
        $this->missingSlackUsers($members);
        $this->googleGroupIssues($members);
//        $this->

        $this->printIssues();
    }


    private function printIssues()
    {
        $this->info("There are {$this->issues->count()} total issues.");
        $this->info("");
        collect($this->issues->keys())
            ->each(function ($key) {
                $knownIssues = collect($this->issues->get($key));
                $this->info("$key ({$knownIssues->count()})");

                $knownIssues
                    ->map(function ($issue) {
                        $this->info(">>> $issue");
                    });
                $this->info("");
            });
    }

    /**
     * @throws ApiCallFailed
     */
    private function getMembers()
    {
        $customers = $this->wooCommerceApi->customers->list();

        $subscriptions = $this->wooCommerceApi->subscriptions->list();

        $members = $customers->map(function ($customer) use ($subscriptions) {
            $isMember = $subscriptions
                ->where('customer_id', $customer['id'])
                ->where('status', 'active')
                ->isNotEmpty();

            $meta_data = collect($customer['meta_data']);
            $card_string = $meta_data->where('key', 'access_card_number')->first()['value'];
            $cards = is_null($card_string) ? collect() : collect(explode(",", $card_string))
                ->map(function ($card) {
                    return ltrim($card, "0");
                });

            return [
                "id" => $customer["id"],
                "first_name" => $customer['first_name'],
                "last_name" => $customer['last_name'],
                "email" => is_null($customer["email"]) ? null : Str::lower($customer["email"]),
                "is_member" => $isMember,
                "cards" => $cards,
                "slack_id" => $meta_data->where('key', 'access_slack_id')->first()['value'],
            ];
        });

        $members = $members->concat(PaypalBasedMember::all()
            ->map(function ($member) {
                return [
                    "id" => $member->paypal_id,
                    "first_name" => $member->first_name,
                    "last_name" => $member->last_name,
                    "email" => is_null($member->email) ? null : Str::lower($member->email),
                    "is_member" => $member->active,
                    "cards" => is_null($member->card) ? collect() : collect([$member->card]),
                    "slack_id" => $member->slack_id,
                ];
            }));

        return $members;
    }

    /**
     * Identify any issues where there is an active card listed for someone, but we have no record of them being an
     * active member.
     * @param $members
     */
    private function unknownActiveCard(Collection $members)
    {
        /** @var ActiveCardHolderUpdate $activeCardHolderUpdate */
        $activeCardHolderUpdate = ActiveCardHolderUpdate::latest()->first();
        if (is_null($activeCardHolderUpdate)) {
            return;
        }

        $card_holders = collect($activeCardHolderUpdate->card_holders);
        $card_holders
            ->each(function ($card_holder) use ($members) {
                $membersWithCard = $members
                    ->filter(function ($member) use ($card_holder) {
                        return $member['cards']->contains(ltrim($card_holder["card_num"], "0"));
                    });

                if ($membersWithCard->count() == 0) {
                    $message = "{$card_holder["first_name"]} {$card_holder["last_name"]} has the active card ({$card_holder["card_num"]}) but I have no membership record of them with that card.";
                    $this->issues->add(self::ISSUE_WITH_A_CARD, $message);

                    return;
                }

                if ($membersWithCard->count() > 1) {
                    $message = "{$card_holder["first_name"]} {$card_holder["last_name"]} has the active card ({$card_holder["card_num"]}) but is connected to multiple accounts.";
                    $this->issues->add(self::ISSUE_WITH_A_CARD, $message);

                    return;
                }

                $member = $membersWithCard->first();

                if ($card_holder["first_name"] != $member["first_name"] ||
                    $card_holder["last_name"] != $member["last_name"]) {
                    $message = "{$card_holder["first_name"]} {$card_holder["last_name"]} has the active card ({$card_holder["card_num"]}) but is listed as {$member["first_name"]} {$member["last_name"]} in our records.";
                    $this->issues->add(self::ISSUE_WITH_A_CARD, $message);
                }

                if (!$member["is_member"]) {
                    $message = "{$card_holder["first_name"]} {$card_holder["last_name"]} has the active card ({$card_holder["card_num"]}) but is not currently a member.";
                    $this->issues->add(self::ISSUE_WITH_A_CARD, $message);
                }
            });

        $members
            ->filter(function ($member) {
                return !is_null($member["first_name"]) &&
                    !is_null($member["last_name"]) &&
                    $member["is_member"];
            })
            ->each(function ($member) use ($card_holders) {
                $member["cards"]->each(function ($card) use ($member, $card_holders) {
                    $cardActive = $card_holders->contains("card_num", $card);
                    if (!$cardActive) {
                        $message = "{$member["first_name"]} {$member["last_name"]} has the card $card but it doesn't appear to be active";
                        $this->issues->add(self::ISSUE_WITH_A_CARD, $message);
                    }
                });
            });
    }

    private function extraSlackUsers(Collection $members)
    {
        $slackUsers = $this->slackApi->users_list()
            ->filter(function ($user) {
                if (array_key_exists("is_bot", $user) && $user["is_bot"]) {
                    return false;
                }

                if (
                    $user["id"] == "UNEA0SKK3" || // slack-api
                    $user["id"] == "USLACKBOT" // slackbot
                ) {
                    return false;
                }

                return true;
            });

        $slackUsers
            ->each(function ($user) use ($members) {
                $membersForSlackId = $members
                    ->filter(function ($member) use ($user) {
                        return $member["slack_id"] == $user["id"];
                    });

                if ($membersForSlackId->count() == 0) {
                    if ($this->isFullSlackUser($user)) {
                        $message = "{$user["name"]} with slack id ({$user["id"]}) is a full user in slack but I have no membership record of them.";
                        $this->issues->add(self::ISSUE_SLACK_ACCOUNT, $message);
                    }
                    return;
                }

                $member = $membersForSlackId->first();

                if ($member["is_member"]) {
                    if (array_key_exists("is_invited_user", $user) && $user["is_invited_user"]) {
                        return; // Do nothing, we've sent the invite and that's all we can do.
                    }

                    if (array_key_exists("deleted", $user) && $user["deleted"]) {
                        $message = "{$member["first_name"]} {$member["last_name"]} with slack id ({$user["id"]}) is deleted, but they are a member";
                        $this->issues->add(self::ISSUE_SLACK_ACCOUNT, $message);
                        return;
                    }

                    if (array_key_exists("is_restricted", $user) && $user["is_restricted"]) {
                        $message = "{$member["first_name"]} {$member["last_name"]} with slack id ({$user["id"]}) is restricted, but they are a member";
                        $this->issues->add(self::ISSUE_SLACK_ACCOUNT, $message);
                        return;
                    }
                    if (array_key_exists("is_ultra_restricted", $user) && $user["is_ultra_restricted"]) {
                        $message = "{$member["first_name"]} {$member["last_name"]} with slack id ({$user["id"]}) is ultra restricted, but they are a member";
                        $this->issues->add(self::ISSUE_SLACK_ACCOUNT, $message);
                        return;
                    }
                } else if ($this->isFullSlackUser($user)) {
                    $message = "{$member["first_name"]} {$member["last_name"]} with slack id ({$user["id"]}) is not an active member but they have a full slack account.";
                    $this->issues->add(self::ISSUE_SLACK_ACCOUNT, $message);
                }
            });
    }

    private function isFullSlackUser($slackUser)
    {

        if (
            (array_key_exists("deleted", $slackUser) && $slackUser["deleted"]) ||
            (array_key_exists("is_restricted", $slackUser) && $slackUser["is_restricted"]) ||
            (array_key_exists("is_ultra_restricted", $slackUser) && $slackUser["is_ultra_restricted"])
        ) {
            return false;
        }
        return true;
    }

    private function missingSlackUsers(Collection $members)
    {
        $slackUsers = $this->slackApi->users_list();

        $members
            ->each(function ($member) use ($slackUsers) {
                if (!$member["is_member"]) {
                    return;
                }

                $slackForMember = $slackUsers
                    ->filter(function ($user) use ($member) {
                        return $member["slack_id"] == $user["id"];
                    });

                if ($slackForMember->count() == 0) {
                    $message = "{$member["first_name"]} {$member["last_name"]} ({$member["id"]}) doesn't appear to have a slack account";
                    $this->issues->add(self::ISSUE_SLACK_ACCOUNT, $message);
                }
            });
    }

    private function googleGroupIssues(Collection $members)
    {
        $groups = $this->googleApi->groupsForDomain("denhac.org")
            ->filter(function ($group) {
                // TODO handle excluded groups in a better way
                return $group != "denhac@denhac.org" &&
                    $group != "lpfmerrors@denhac.org";
            });

        $emailsToGroups = collect();

        $groups->each(function ($group) use (&$emailsToGroups) {
            $membersInGroup = $this->googleApi->group($group)->list();

            $membersInGroup->each(function ($groupMember) use ($group, &$emailsToGroups) {
                $groupsForEmail = $emailsToGroups->get($groupMember, collect());
                $groupsForEmail->add($group);
                $emailsToGroups->put($groupMember, $groupsForEmail);
            });
        });

        $emailsToGroups->each(function ($groupsForEmail, $email) use ($groups, $members) {
            /** @var Collection $groupsForEmail */

            // Ignore groups of ours that are part of another group
            if ($groups->contains($email)) {
                return;
            }

            $membersForEmail = $members
                ->filter(function ($member) use ($email) {
                    return $member["email"] == $email;
                });

            if ($membersForEmail->count() > 1) {
                $message = "More than 2 members exist for email address $email";
                $this->issues->add(self::ISSUE_GOOGLE_GROUPS, $message);
                return;
            }

            if ($membersForEmail->count() == 0) {
                $message = "No member found for email address $email in groups: {$groupsForEmail->implode(", ")}";
                $this->issues->add(self::ISSUE_GOOGLE_GROUPS, $message);
                return;
            }

            $member = $membersForEmail->first();

            if (!$member["is_member"]) {
                $message = "{$member["first_name"]} {$member["last_name"]} with email ($email) is not an active member but is in groups: {$groupsForEmail->implode(", ")}";
                $this->issues->add(self::ISSUE_GOOGLE_GROUPS, $message);
            }
        });

        $members->each(function ($member) use ($emailsToGroups) {
            $memberEmail = $member["email"];

            if (is_null($memberEmail)) {
                return;
            }

            if (!$member["is_member"]) {
                return;
            }

            $membersGroupMailing = "members@denhac.org"; // TODO dedupe this
            if ($emailsToGroups->has($memberEmail) &&
                $emailsToGroups->get($memberEmail)->contains($membersGroupMailing)) {
                return;
            }

            $message = "{$member["first_name"]} {$member["last_name"]} with email ($memberEmail) is an active member but is not part of $membersGroupMailing";
            $this->issues->add(self::ISSUE_GOOGLE_GROUPS, $message);
        });
    }
}
