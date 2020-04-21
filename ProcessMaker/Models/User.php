<?php

namespace ProcessMaker\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Laravel\Passport\HasApiTokens;
use ProcessMaker\Models\RequestUserPermission;
use ProcessMaker\Query\Traits\PMQL;
use ProcessMaker\Traits\HasAuthorization;
use ProcessMaker\Traits\SerializeToIso8601;
use Spatie\MediaLibrary\HasMedia\HasMedia;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;

class User extends Authenticatable implements HasMedia
{
    use PMQL;
    use HasApiTokens;
    use Notifiable;
    use HasMediaTrait;
    use HasAuthorization;
    use SerializeToIso8601;
    use SoftDeletes;

    protected $connection = 'processmaker';

    //Disk
    public const DISK_PROFILE = 'profile';
    //collection media library
    public const COLLECTION_PROFILE = 'profile';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     *
     * @OA\Schema(
     *   schema="usersEditable",
     *   @OA\Property(property="email", type="string", format="email"),
     *   @OA\Property(property="firstname", type="string"),
     *   @OA\Property(property="lastname", type="string"),
     *   @OA\Property(property="username", type="string"),
     *   @OA\Property(property="address", type="string"),
     *   @OA\Property(property="city", type="string"),
     *   @OA\Property(property="state", type="string"),
     *   @OA\Property(property="postal", type="string"),
     *   @OA\Property(property="country", type="string"),
     *   @OA\Property(property="phone", type="string"),
     *   @OA\Property(property="fax", type="string"),
     *   @OA\Property(property="cell", type="string"),
     *   @OA\Property(property="title", type="string"),
     *   @OA\Property(property="timezone", type="string"),
     *   @OA\Property(property="datetime_format", type="string"),
     *   @OA\Property(property="language", type="string"),
     *   @OA\Property(property="is_administrator", type="boolean"),
     *   @OA\Property(property="expires_at", type="string"),
     *   @OA\Property(property="loggedin_at", type="string"),
     *   @OA\Property(property="remember_token", type="string"),
     *   @OA\Property(property="status", type="string", enum={"ACTIVE", "INACTIVE"}),
     *   @OA\Property(property="group_id", type="string"),
     *   @OA\Property(property="member_type", type="string"),
     *   @OA\Property(property="member_id", type="string"),
     *   @OA\Property(property="fullname", type="string"),
     *   @OA\Property(property="avatar", type="string"),
     *   @OA\Property(property="media", type="array", @OA\Items(type="string")),
     *   @OA\Property(property="birthdate", type="string"),
     * ),
     * @OA\Schema(
     *   schema="users",
     *   allOf={
     *      @OA\Schema(ref="#/components/schemas/usersEditable"),
     *      @OA\Schema(
     *          type="object",
     *          @OA\Property(property="id", type="string", format="id"),
     *          @OA\Property(property="created_at", type="string", format="date-time"),
     *          @OA\Property(property="updated_at", type="string", format="date-time"),
     *          @OA\Property(property="deleted_at", type="string", format="date-time"),
     *      )
     *   },
     * )
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'firstname',
        'lastname',
        'status',
        'address',
        'city',
        'state',
        'postal',
        'country',
        'phone',
        'fax',
        'cell',
        'title',
        'birthdate',
        'timezone',
        'datetime_format',
        'language',
        'meta',
    ];

    protected $appends = [
        'fullname',
        'avatar',
    ];

    protected $casts = [
        'is_administrator' => 'bool',
        'meta' => 'object',
        'active_at' => 'datetime',
    ];

    /**
     * Validation rules
     *
     * @param $existing
     *
     * @return array
     */
    public static function rules($existing = null)
    {
        $unique = Rule::unique('users')->ignore($existing);

        $checkUserIsDeleted = function ($attribute, $value, $fail) use ($existing) {
            if (!$existing) {
                $user = User::withTrashed()->where($attribute, $value)->first();
                if ($user) {
                    $fail(
                        __(
                            'A user with the username :username and email :email was previously deleted.',
                            ['username' => $user->username, 'email' => $user->email]
                        )
                    );
                }
            }
        };

        return [
            'username' => ['required', 'alpha_spaces', 'min:4', 'max:255' , $unique, $checkUserIsDeleted],
            'firstname' => ['required', 'max:50'],
            'lastname' => ['required', 'max:50'],
            'email' => ['required', 'email', $unique, $checkUserIsDeleted],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'password' => 'required|sometimes|min:6',
            'birthdate' => 'date|nullable'
        ];
    }

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'groupMembersFromMemberable',
        'permissions',
    ];

    /**
     * Scope to only return active users.
     *
     * @var Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Return the full name for this user which is the first name and last
     * name separated with a space.
     *
     * @return string
     */
    public function getFullName()
    {
        return implode(" ", [
            $this->firstname,
            $this->lastname
        ]);
    }

    public function hasPermissionsFor($resource)
    {
        if ($this->is_administrator) {
            $perms = Permission::all(['name'])->pluck('name');
        } else {
            $perms = collect(session('permissions'));
        }

        $filtered = $perms->filter(function ($value) use ($resource) {
            $match = preg_match("/(.+)-{$resource}/", $value);
            if ($match === 1) {
                return true;
            } else {
                return false;
            }
        });

        return $filtered->values();
    }

    public function groupMembersFromMemberable()
    {
        return $this->morphMany(GroupMember::class, 'member', null, 'member_id');
    }

    public function groups()
    {
        return $this->morphToMany('ProcessMaker\Models\Group', 'member', 'group_members');
    }

    public function permissions()
    {
        return $this->morphToMany('ProcessMaker\Models\Permission', 'assignable');
    }

    public function processesFromProcessable()
    {
        return $this->morphToMany('ProcessMaker\Models\Process', 'processable');
    }

    /**
     * Get the full name as an attribute.
     *
     * @return string
     */
    public function getFullnameAttribute()
    {
        return $this->getFullName();
    }

    /**
     * Get the avatar URL
     *
     * @return string
     */
    public function getAvatarAttribute()
    {
        return $this->getAvatar();
    }

    /**
     * Define the avatar mutator. Within, we set the avatar attribute only if
     * it is not null. This prevents the model from attempting to send an
     * avatar field to the database on update, which has been known to
     * cause errors from time to time.
     *
     * @return string
     */
    public function setAvatarAttribute($value = null)
    {
        if ($value) {
            $this->attributes['avatar'] = $value;
        }
    }

    /**
     * Get url Avatar
     *
     * @return string
     */
    public function getAvatar()
    {
        $mediaFile = $this->getMedia(self::COLLECTION_PROFILE);
        $url = '';
        foreach ($mediaFile as $media) {
            $url = $media->getFullUrl();
        }
        return $url;
    }

    /**
     * Returns the list of notifications not read by the user
     *
     * @return \Illuminate\Support\Collection
     */
    public function activeNotifications()
    {
        $notifications = Notification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $this->id)
            ->whereNull('read_at')
            ->get();

        $data = [];
        foreach ($notifications as $notification) {
            $notificationData = json_decode($notification->data, false);
            $notificationData->id = $notification->id;
            $data[] = $notificationData;
        }

        return collect($data);
    }

    /**
     * User as assigned.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function assigned()
    {
        return $this->morphMany(ProcessTaskAssignment::class, 'assigned', 'assignment_type', 'assignment_id');
    }

    /**
     * Check if the user can do any of the listed permissions.
     * If so, return the permission name, otherwise false
     */
    public function canAny($permissions)
    {
        foreach (explode("|", $permissions) as $permission) {
            if ($this->can($permission)) {
                return $permission;
            }
        }
        return false;
    }

    /**
     * Find the user instance for the given username.
     * This ensures we are utilizing our username field for grants for oauth.
     *
     * @param  string  $username
     * @return \App\User
     */
    public function findForPassport($username)
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Check if the user can self-serve themselves a task
     *
     * @param ProcessRequestToken $task
     * @return boolean
     */
    public function canSelfServe(ProcessRequestToken $task)
    {
        if (!$task->is_self_service) {
            return false;
        }

        return collect($task->self_service_groups)
            ->intersect(
                $this->groups()->pluck('groups.id')
            )->count() > 0;
    }

    /**
     * Update one request_user_permissions
     *
     * @param ProcessRequest $request
     *
     * @return void
     */
    public function updatePermissionToRequest(ProcessRequest $request)
    {
        $permission = RequestUserPermission::firstOrNew(['request_id' => $request->getKey(), 'user_id' => $this->getKey()]);
        $permission->can_view = $this->can('view', $request);
        $permission->save();
    }

    public function updatePermissionsToRequests()
    {
        // Update existing request_user_permissions
        $permissions = RequestUserPermission::with('request')->whereHas('request', function ($query) {
            $query->where('request_user_permissions.user_id', $this->getKey());
            $query->whereRaw('process_requests.updated_at > request_user_permissions.updated_at');
        })->get();
        foreach ($permissions as $permission) {
            $permission->can_view = $this->can('view', $permission->request);
            $permission->save();
        }
        // Add new request_user_permissions
        $requests = ProcessRequest::whereRaw(
            'id not in (select request_id from request_user_permissions where user_id=?)',
            [$this->getKey()]
        )->get();
        foreach($requests as $request) {
            $this->updatePermissionToRequest($request);
        }
    }
}
