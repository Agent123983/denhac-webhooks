<?php

namespace App\Reactors;

use App\Actions\Slack\AddToChannel;
use App\Actions\Slack\AddToUserGroup;
use App\Actions\Slack\RemoveFromChannel;
use App\Actions\Slack\RemoveFromUserGroup;
use App\Actions\Slack\UpdateSlackUserProfileMembership;
use App\Customer;
use App\FeatureFlags;
use App\Jobs\DemoteMemberToPublicOnlyMemberInSlack;
use App\Jobs\InviteCustomerNeedIdCheckOnlyMemberInSlack;
use App\Jobs\MakeCustomerRegularMemberInSlack;
use App\Slack\Channels;
use App\StorableEvents\CustomerBecameBoardMember;
use App\StorableEvents\CustomerRemovedFromBoard;
use App\StorableEvents\MembershipActivated;
use App\StorableEvents\MembershipDeactivated;
use App\StorableEvents\SubscriptionUpdated;
use App\StorableEvents\UserMembershipCreated;
use App\UserMembership;
use Spatie\EventSourcing\EventHandlers\EventHandler;
use Spatie\EventSourcing\EventHandlers\HandlesEvents;
use YlsIdeas\FeatureFlags\Facades\Features;

final class SlackReactor implements EventHandler
{
    use HandlesEvents;

    public function onSubscriptionUpdated(SubscriptionUpdated $event)
    {
        if ($event->subscription['status'] != 'need-id-check') {
            return;
        }

        if (Features::accessible(FeatureFlags::NEED_ID_CHECK_GETS_ADDED_TO_SLACK_AND_EMAIL)) {
            dispatch(new MakeCustomerRegularMemberInSlack($event->subscription['customer_id']));
        } else {
            dispatch(new InviteCustomerNeedIdCheckOnlyMemberInSlack($event->subscription['customer_id']));
        }
    }

    public function onMembershipActivated(MembershipActivated $event)
    {
        dispatch(new MakeCustomerRegularMemberInSlack($event->customerId));
    }

    public function onMembershipDeactivated(MembershipDeactivated $event)
    {
        /** @var Customer $customer */
        $customer = Customer::find($event->customerId);

        if(! is_null($customer)) {
            UpdateSlackUserProfileMembership::queue()->execute($customer->slack_id);
        }

        if (Features::accessible(FeatureFlags::KEEP_MEMBERS_IN_SLACK_AND_EMAIL)) {
            return;
        }

        dispatch(new DemoteMemberToPublicOnlyMemberInSlack($event->customerId));
    }

    public function onCustomerBecameBoardMember(CustomerBecameBoardMember $event)
    {
        AddToChannel::queue()->execute($event->customerId, Channels::BOARD);
        AddToUserGroup::queue()->execute($event->customerId, 'theboard');
    }

    public function onCustomerRemovedFromBoard(CustomerRemovedFromBoard $event)
    {
        RemoveFromChannel::queue()->execute($event->customerId, Channels::BOARD);
        RemoveFromUserGroup::queue()->execute($event->customerId, 'theboard');
    }

    public function onUserMembershipCreated(UserMembershipCreated $event)
    {
        if ($event->membership['status'] != 'active') {
            return;
        }

        $customerId = $event->membership['customer_id'];
        $plan_id = $event->membership['plan_id'];

        if ($plan_id == UserMembership::MEMBERSHIP_3DP_USER) {
            AddToChannel::queue()->execute($customerId, Channels::PRINTER_3D);
        }

        if ($plan_id == UserMembership::MEMBERSHIP_LASER_CUTTER_USER) {
            AddToChannel::queue()->execute($customerId, Channels::LASER);
        }
    }
}
