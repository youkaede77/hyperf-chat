<?php

namespace App\Controller\Api\V1;

use App\Model\UsersFriend;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Phper666\JWTAuth\Middleware\JWTAuthMiddleware;
use App\Service\GroupService;
use App\Model\UsersChatList;
use App\Model\Group\UsersGroup;
use App\Model\Group\UsersGroupMember;
use App\Model\Group\UsersGroupNotice;

/**
 * Class GroupController
 *
 * @Controller(path="/api/v1/group")
 * @Middleware(JWTAuthMiddleware::class)
 *
 * @package App\Controller\Api\V1
 */
class GroupController extends CController
{
    /**
     * @Inject
     * @var GroupService
     */
    public $groupService;

    /**
     * 创建群组
     *
     * @RequestMapping(path="create", methods="post")
     *
     * @return mixed
     */
    public function create()
    {
        $params = $this->request->all();
        $this->validate($params, [
            'group_name' => 'required',
            'group_profile' => 'required',
            'uids' => 'required',
        ]);

        $friend_ids = array_filter(explode(',', $params['uids']));

        [$isTrue, $data] = $this->groupService->create($this->uid(), [
            'name' => $params['group_name'],
            'avatar' => $params['avatar'] ?? '',
            'profile' => $params['group_profile']
        ], array_unique($friend_ids));

        if (!$isTrue) {
            return $this->response->fail('创建群聊失败，请稍后再试...');
        }

        //群聊创建成功后需要创建聊天室并发送消息通知
        // ... 包装消息推送到队列

        return $this->response->success([
            'group_id' => $data['group_id']
        ], '群聊创建成功...');
    }

    /**
     * 解散群组接口
     *
     * @RequestMapping(path="dismiss", methods="post")
     */
    public function dismiss()
    {
        $params = $this->request->inputs(['group_id']);
        $this->validate($params, [
            'group_id' => 'required|integer',
        ]);

        $isTrue = $this->groupService->dismiss($params['group_id'], $this->uid());
        if (!$isTrue) {
            return $this->response->fail('群组解散失败...');
        }

        // ... 推送群消息

        return $this->response->success([], '群组解散成功...');
    }

    /**
     * 邀请好友加入群组接口
     *
     * @RequestMapping(path="invite", methods="post")
     */
    public function invite()
    {
        $params = $this->request->inputs(['group_id', 'uids']);
        $this->validate($params, [
            'group_id' => 'required|integer',
            'uids' => 'required',
        ]);

        $uids = array_filter(explode(',', $params['uids']));

        [$isTrue, $record_id] = $this->groupService->invite($this->uid(), $params['group_id'], array_unique($uids));
        if (!$isTrue) {
            return $this->response->fail('邀请好友加入群聊失败...');
        }

        // 推送入群消息
        // ...

        return $this->response->success([], '好友已成功加入群聊...');
    }

    /**
     * 退出群组接口
     *
     * @RequestMapping(path="secede", methods="post")
     */
    public function secede()
    {
        $params = $this->request->inputs(['group_id']);
        $this->validate($params, [
            'group_id' => 'required|integer'
        ]);

        [$isTrue, $record_id] = $this->groupService->quit($this->uid(), $params['group_id']);
        if (!$isTrue) {
            return $this->response->fail('退出群组失败...');
        }

        // 推送消息通知
        // ...

        return $this->response->success([], '已成功退出群组...');
    }

    /**
     * 编辑群组信息
     *
     * @RequestMapping(path="edit", methods="post")
     */
    public function editDetail()
    {
        $params = $this->request->inputs(['group_id', 'group_name', 'group_profile', 'avatar']);
        $this->validate($params, [
            'group_id' => 'required|integer',
            'group_name' => 'required',
            'group_profile' => 'required',
            'avatar' => 'required',
        ]);

        $result = UsersGroup::where('id', $params['group_id'])->where('user_id', $this->uid())->update([
            'group_name' => $params['group_name'],
            'group_profile' => $params['group_profile'],
            'avatar' => $params['avatar']
        ]);

        // 推送消息通知
        // ...

        return $result
            ? $this->response->success([], '群组信息修改成功...')
            : $this->response->fail('群组信息修改失败...');
    }

    /**
     * 移除指定成员（管理员权限）
     *
     * @RequestMapping(path="remove-members", methods="post")
     */
    public function removeMembers()
    {
        $params = $this->request->inputs(['group_id', 'members_ids']);
        $this->validate($params, [
            'group_id' => 'required|integer',
            'members_ids' => 'required|array'
        ]);

        [$isTrue, $record_id] = $this->groupService->removeMember($params['group_id'], $this->uid(), $params['members_ids']);
        if (!$isTrue) {
            return $this->response->fail('群聊用户移除失败...');
        }

        // 推送消息通知
        // ...

        return $this->response->success([], '已成功退出群组...');
    }

    /**
     * 获取群信息接口
     *
     * @RequestMapping(path="detail", methods="get")
     */
    public function detail()
    {
        $group_id = $this->request->input('group_id', 0);

        $user_id = $this->uid();
        $groupInfo = UsersGroup::leftJoin('users', 'users.id', '=', 'users_group.user_id')
            ->where('users_group.id', $group_id)->where('users_group.status', 0)->first([
                'users_group.id', 'users_group.user_id',
                'users_group.group_name',
                'users_group.group_profile', 'users_group.avatar',
                'users_group.created_at',
                'users.nickname'
            ]);

        if (!$groupInfo) {
            return $this->response->success([]);
        }

        $notice = UsersGroupNotice::where('group_id', $group_id)
            ->where('is_delete', 0)
            ->orderBy('id', 'desc')
            ->first(['title', 'content']);

        return $this->response->success([
            'group_id' => $groupInfo->id,
            'group_name' => $groupInfo->group_name,
            'group_profile' => $groupInfo->group_profile,
            'avatar' => $groupInfo->avatar,
            'created_at' => $groupInfo->created_at,
            'is_manager' => $groupInfo->user_id == $user_id,
            'manager_nickname' => $groupInfo->nickname,
            'visit_card' => UsersGroupMember::visitCard($user_id, $group_id),
            'not_disturb' => UsersChatList::where('uid', $user_id)->where('group_id', $group_id)->where('type', 2)->value('not_disturb') ?? 0,
            'notice' => $notice ? $notice->toArray() : []
        ]);
    }

    /**
     * 设置用户群名片
     *
     * @RequestMapping(path="set-group-card", methods="post")
     */
    public function setGroupCard()
    {
        $params = $this->request->inputs(['group_id', 'visit_card']);
        $this->validate($params, [
            'group_id' => 'required|integer',
            'visit_card' => 'required'
        ]);

        $isTrue = UsersGroupMember::where('group_id', $params['group_id'])
            ->where('user_id', $this->uid())
            ->where('status', 0)
            ->update(['visit_card' => $params['visit_card']]);

        return $isTrue
            ? $this->response->success([], '群名片修改成功...')
            : $this->response->error('群名片修改失败...');
    }

    /**
     * 获取用户可邀请加入群组的好友列表
     *
     * @RequestMapping(path="invite-friends", methods="get")
     */
    public function getInviteFriends()
    {
        $group_id = $this->request->input('group_id', 0);
        $friends = UsersFriend::getUserFriends($this->uid());
        if ($group_id > 0 && $friends) {
            if ($ids = UsersGroupMember::getGroupMemberIds($group_id)) {
                foreach ($friends as $k => $item) {
                    if (in_array($item['id'], $ids)) unset($friends[$k]);
                }
            }

            $friends = array_values($friends);
        }

        return $this->response->success($friends);
    }

    /**
     * 获取群组成员列表
     *
     * @RequestMapping(path="members", methods="get")
     */
    public function getGroupMembers()
    {
        $user_id = $this->uid();
        $group_id = $this->request->input('group_id', 0);

        // 判断用户是否是群成员
        if (!UsersGroup::isMember($group_id, $user_id)) {
            return $this->response->fail('非法操作...');
        }

        $members = UsersGroupMember::select([
            'users_group_member.id', 'users_group_member.group_owner as is_manager', 'users_group_member.visit_card',
            'users_group_member.user_id', 'users.avatar', 'users.nickname', 'users.gender',
            'users.motto',
        ])
            ->leftJoin('users', 'users.id', '=', 'users_group_member.user_id')
            ->where([
                ['users_group_member.group_id', '=', $group_id],
                ['users_group_member.status', '=', 0],
            ])->orderBy('is_manager', 'desc')->get()->toArray();

        return $this->response->success($members);
    }

    /**
     * 获取群组公告列表
     *
     * @RequestMapping(path="notices", methods="get")
     */
    public function getGroupNotices()
    {
        $user_id = $this->uid();
        $group_id = $this->request->input('group_id', 0);

        // 判断用户是否是群成员
        if (!UsersGroup::isMember($group_id, $user_id)) {
            return $this->response->fail('非管理员禁止操作...');
        }

        $rows = UsersGroupNotice::leftJoin('users', 'users.id', '=', 'users_group_notice.user_id')
            ->where([
                ['users_group_notice.group_id', '=', $group_id],
                ['users_group_notice.is_delete', '=', 0]
            ])
            ->orderBy('users_group_notice.id', 'desc')
            ->get([
                'users_group_notice.id',
                'users_group_notice.user_id',
                'users_group_notice.title',
                'users_group_notice.content',
                'users_group_notice.created_at',
                'users_group_notice.updated_at',
                'users.avatar', 'users.nickname',
            ])->toArray();

        return $this->response->success($rows);
    }

    /**
     * 创建/编辑群公告
     *
     * @RequestMapping(path="edit-notice", methods="post")
     */
    public function editNotice()
    {
        $params = $this->request->inputs(['group_id', 'notice_id', 'title', 'content']);
        $this->validate($params, [
            'group_id' => 'required|integer',
            'notice_id' => 'required|integer',
            'title' => 'required',
            'content' => 'required',
        ]);

        $user_id = $this->uid();

        // 判断用户是否是管理员
        if (!UsersGroup::isManager($user_id, $params['group_id'])) {
            return $this->response->fail('非管理员禁止操作...');
        }

        // 判断是否是新增数据
        if (empty($data['notice_id'])) {
            $result = UsersGroupNotice::create([
                'group_id' => $data['group_id'],
                'title' => $data['title'],
                'content' => $data['content'],
                'user_id' => $user_id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if (!$result) {
                return $this->response->fail('添加群公告信息失败...');
            }

            // ... 推送群消息
            return $this->response->success([], '添加群公告信息成功...');
        }

        $result = UsersGroupNotice::where('id', $data['notice_id'])->update([
            'title' => $data['title'],
            'content' => $data['content'],
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $result
            ? $this->response->success('修改群公告信息成功...')
            : $this->response->fail('修改群公告信息成功...');
    }

    /**
     * 删除群公告(软删除)
     *
     * @RequestMapping(path="delete-notice", methods="post")
     */
    public function deleteNotice()
    {
        $params = $this->request->inputs(['group_id', 'notice_id']);
        $this->validate($params, [
            'group_id' => 'required|integer',
            'notice_id' => 'required|integer'
        ]);

        $user_id = $this->uid();

        // 判断用户是否是管理员
        if (!UsersGroup::isManager($user_id, $params['group_id'])) {
            return $this->response->fail('非法操作...');
        }

        $result = UsersGroupNotice::where('id', $params['group_id'])
            ->where('group_id', $params['group_id'])
            ->update([
                'is_delete' => 1,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);

        return $result
            ? $this->response->success('公告删除成功...')
            : $this->response->fail('公告删除失败...');
    }
}