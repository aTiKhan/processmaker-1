<?php

namespace ProcessMaker\Http\Controllers\Api;

use Illuminate\Http\Request;
use ProcessMaker\Http\Controllers\Controller;
use ProcessMaker\Http\Resources\ApiCollection;
use ProcessMaker\Models\Group;
use ProcessMaker\Http\Resources\Groups as GroupResource;
use ProcessMaker\Models\GroupMember;
use ProcessMaker\Models\User;

class GroupController extends Controller
{
    /**
     * A whitelist of attributes that should not be
     * sanitized by our SanitizeInput middleware.
     *
     * @var array
     */
    public $doNotSanitize = [
        //
    ];

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return ApiCollection
     *
     * @OA\Get(
     *     path="/groups",
     *     summary="Returns all groups that the user has access to",
     *     operationId="getGroups",
     *     tags={"Groups"},
     *     @OA\Parameter(ref="#/components/parameters/filter"),
     *     @OA\Parameter(ref="#/components/parameters/order_by"),
     *     @OA\Parameter(ref="#/components/parameters/order_direction"),
     *     @OA\Parameter(ref="#/components/parameters/per_page"),
     *     @OA\Parameter(ref="#/components/parameters/include"),
     *
     *     @OA\Response(
     *         response=200,
     *         description="list of groups",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/groups"),
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 ref="#/components/schemas/metadata",
     *             ),
     *         ),
     *     ),
     * )
     */
    public function index(Request $request)
    {
        $include = $request->input('include', '');
        $query = Group::query();
        if ($include) {
            $include = explode(',', $include);
            $count = array_search('membersCount', $include);
            if ($count !== false) {
                unset($include[$count]);
                $query->withCount('groupMembers');
            }
            if ($include) {
                $query->with($include);
            }
        }
        $filter = $request->input('filter', '');
        if (!empty($filter)) {
            $filter = '%' . $filter . '%';
            $query->where(function ($query) use ($filter) {
                $query->Where('name', 'like', $filter)
                    ->orWhere('description', 'like', $filter);
            });
        }
        $status = $request->input('status', null);
        if ($status) {
            $query->where('status', $status);
        }

        $response =
            $query->orderBy(
                $request->input('order_by', 'name'),
                $request->input('order_direction', 'ASC')
            )
                ->paginate($request->input('per_page', 10));
        return new ApiCollection($response);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     *
     * @return GroupResource
     * @throws \Throwable
     *
     * @OA\Post(
     *     path="/groups",
     *     summary="Save a new group",
     *     operationId="createGroup",
     *     tags={"Groups"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/groupsEditable")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="success",
     *         @OA\JsonContent(ref="#/components/schemas/groups")
     *     ),
     * )
     */
    public function store(Request $request)
    {
        $request->validate(Group::rules());
        $group = new Group();
        $group->fill($request->input());
        $group->saveOrFail();
        return new GroupResource($group);
    }

    /**
     * Display the specified resource.
     *
     * @param Group $group
     * @return GroupResource
     *
     * @OA\Get(
     *     path="/groups/{group_id}",
     *     summary="Get single group by ID",
     *     operationId="getGroupById",
     *     tags={"Groups"},
     *     @OA\Parameter(
     *         description="ID of group to return",
     *         in="path",
     *         name="group_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully found the group",
     *         @OA\JsonContent(ref="#/components/schemas/groups")
     *     ),
     * )
     */
    public function show(Group $group)
    {
        return new GroupResource($group);
    }

    /**
     * Update a user
     *
     * @param Group $group
     * @param Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws \Throwable
     *
     * @OA\Put(
     *     path="/groups/{group_id}",
     *     summary="Update a group",
     *     operationId="updateGroup",
     *     tags={"Groups"},
     *     @OA\Parameter(
     *         description="ID of group to return",
     *         in="path",
     *         name="group_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\RequestBody(
     *       required=true,
     *       @OA\JsonContent(ref="#/components/schemas/groupsEditable")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="success",
     *     ),
     * )
     */
    public function update(Group $group, Request $request)
    {
        $request->validate(Group::rules($group));
        $group->fill($request->input());
        $group->saveOrFail();
        return response([], 204);
    }

    /**
     * Delete a user
     *
     * @param Group $group
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws \Exception
     *
     * @OA\Delete(
     *     path="/groups/{group_id}",
     *     summary="Delete a group",
     *     operationId="deleteGroup",
     *     tags={"Groups"},
     *     @OA\Parameter(
     *         description="ID of group to return",
     *         in="path",
     *         name="group_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="success",
     *     ),
     * )
     */
    public function destroy(Group $group)
    {
        $group->delete();
        return response([], 204);
    }

    /**
     * Display the list of users in a group
     *
     * @param Request $request
     *
     * @return ApiCollection
     *
     * @OA\Get(
     *     path="/group_users/{group_id}",
     *     summary="Returns all users of a group",
     *     operationId="getMembers",
     *     tags={"Group Users"},
     *     @OA\Parameter(
     *         description="ID of notification to return",
     *         in="path",
     *         name="group_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="filter",
     *         in="query",
     *         description="Filter results by string. Searches Name and Status. Status must match exactly. Others can be a substring.",
     *         @OA\Schema(type="string"),
     *     ),
     *     @OA\Parameter(ref="#/components/parameters/order_direction"),
     *     @OA\Parameter(ref="#/components/parameters/per_page"),
     *
     *     @OA\Response(
     *         response=200,
     *         description="list of members of a group",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/users"),
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 ref="#/components/schemas/metadata",
     *             ),
     *         ),
     *     ),
     * )
     */
    public function members(Group $group, Request $request)
    {
        $query = User::query()
            ->leftJoin('group_members', 'users.id', '=', 'group_members.member_id');

        $query->where('group_members.group_id', $group->id);

        $filter = $request->input('filter', '');
        if (!empty($filter)) {
            $filter = '%' . $filter . '%';
            $query->where(function ($query) use ($filter) {
                $query->Where('username', 'like', $filter)
                    ->orWhere('firstname', 'like', $filter)
                    ->orWhere('lastname', 'like', $filter);
            });
        }

        $order_by = 'username';
        $order_direction = 'ASC';

        if ($request->has('order_by')) {
            $order_by = $request->input('order_by');
        }

        if ($request->has('order_direction')) {
            $order_direction = $request->input('order_direction');
        }

        $response =
            $query->orderBy(
                $request->input('order_by', $order_by),
                $request->input('order_direction', $order_direction)
            )
                ->paginate($request->input('per_page', 10));

        return new ApiCollection($response);
    }

}
