<?php

namespace ProcessMaker\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use ProcessMaker\Traits\SerializeToIso8601;
use ProcessMaker\Traits\SqlsrvSupportTrait;

/**
 * Represents a business process definition.
 *
 * @property integer 'id',
 * @property integer 'user_id',
 * @property integer 'commentable_id',
 * @property string 'commentable_type',
 * @property integer 'up',
 * @property integer 'down',
 * @property string 'subject',
 * @property string 'body',
 * @property boolean 'hidden',
 * @property string 'type',
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $created_at
 *
 * @OA\Schema(
 *   schema="commentsEditable",
 *   @OA\Property(property="id", type="string", format="id"),
 *   @OA\Property(property="user_id", type="string", format="id"),
 *   @OA\Property(property="commentable_id", type="string", format="id"),
 *   @OA\Property(property="commentable_type", type="string"),
 *   @OA\Property(property="up", type="integer"),
 *   @OA\Property(property="down", type="integer"),
 *   @OA\Property(property="subject", type="string"),
 *   @OA\Property(property="body", type="string"),
 *   @OA\Property(property="hidden", type="boolean"),
 *   @OA\Property(property="type", type="string", enum={"LOG", "MESSAGE"}),
 * ),
 * @OA\Schema(
 *   schema="comments",
 *   allOf={@OA\Schema(ref="#/components/schemas/commentsEditable")},
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time"),
 * )
 *
 */
class Comment extends Model
{
    use SerializeToIso8601;
    use SqlsrvSupportTrait;
    use SoftDeletes;

    protected $connection = 'data';

    protected $fillable = [
        'user_id', 'commentable_id', 'commentable_type', 'subject', 'body', 'hidden', 'type'
    ];

    protected $casts = [
        'up' => 'array',
        'down' => 'array',
    ];

    public static function rules()
    {
        return [
            'user_id' => 'required',
            'commentable_id' => 'required',
            'commentable_type' => 'required|in:' . ProcessRequestToken::class . ',' . ProcessRequest::class,
            'subject' => 'required',
            'body' => 'required',
            'hidden' => 'required|boolean',
            'type' => 'required|in:LOG,MESSAGE',
        ];

    }

    /**
     * Scope comments hidden
     *
     * @param $query
     * @param $parameter hidden, visible, all
     *
     * @return mixed
     */
    public function scopeHidden($query, $parameter)
    {
        switch ($parameter) {
            case 'visible':
                return $query->where('hidden', false);
                break;
            case 'hidden':
                return $query->where('hidden', true);
                break;
            case 'ALL':
                return $query;
                break;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function commentable()
    {
        return $this->morphTo(null, null, 'commentable_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Children comments with user
     */
    public function children()
    {
        return $this->hasMany(Comment::class, 'commentable_id', 'id')
            ->where('commentable_type', Comment::class)
            ->with('user');
    }
}
