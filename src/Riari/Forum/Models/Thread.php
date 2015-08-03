<?php namespace Riari\Forum\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Riari\Forum\Models\Traits\HasAuthor;

class Thread extends BaseModel
{
    use SoftDeletes, HasAuthor;

    // Eloquent properties
    protected $table        = 'forum_threads';
    protected $fillable     = ['category_id', 'author_id', 'title', 'locked', 'pinned'];
    public    $timestamps   = true;
    protected $with         = ['author'];
    protected $guarded      = ['id'];

    // Thread constants
    const     STATUS_UNREAD  = 'unread';
    const     STATUS_UPDATED = 'updated';

    /**
     * Create a new thread model instance.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->perPage = config('forum.preferences.pagination.threads');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function category()
    {
        return $this->belongsTo('\Riari\Forum\Models\Category');
    }

    public function readers()
    {
        return $this->belongsToMany(
                config('forum.integration.models.user'),
                'forum_threads_read',
                'thread_id',
                'user_id'
            )->withTimestamps();
    }

    public function posts()
    {
        return $this->hasMany('\Riari\Forum\Models\Post');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeRecent($query)
    {
        $cutoff = config('forum.preferences.thread.cutoff_age');
        return $query->where('updated_at', '>', date('Y-m-d H:i:s', strtotime($cutoff)))
            ->orderBy('updated_at', 'desc');
    }

    /*
    |--------------------------------------------------------------------------
    | Attributes
    |--------------------------------------------------------------------------
    */

    // Route attributes

    public function getRouteAttribute()
    {
        return $this->getRoute('forum.thread.show');
    }

    public function getReplyRouteAttribute()
    {
        return $this->getRoute('forum.post.create');
    }

    public function getUpdateRouteAttribute()
    {
        return $this->getRoute('forum.api.v1.thread.update', ['thread' => $this->id]);
    }

    public function getDeleteRouteAttribute()
    {
        return $this->getRoute('forum.api.v1.thread.destroy', ['thread' => $this->id]);
    }

    public function getRestoreRouteAttribute()
    {
        return $this->getRoute('forum.api.v1.thread.restore', ['thread' => $this->id]);
    }

    public function getForceDeleteRouteAttribute()
    {
        return $this->getRoute('forum.api.v1.thread.destroy', ['thread' => $this->id, 'force' => 1]);
    }

    public function getLastPostUrlAttribute()
    {
        return "{$this->route}?page={$this->lastPage}#post-{$this->lastPost->id}";
    }

    // General attributes

    public function getPostsPaginatedAttribute()
    {
        return $this->posts()->paginate(config('forum.preferences.pagination.posts'));
    }

    public function getPageLinksAttribute()
    {
        return $this->postsPaginated->render();
    }

    public function getLastPageAttribute()
    {
        return $this->postsPaginated->lastPage();
    }

    public function getLastPostAttribute()
    {
        return $this->posts()->orderBy('created_at', 'desc')->first();
    }

    public function getLastPostTimeAttribute()
    {
        return $this->lastPost->created_at;
    }

    public function getReplyCountAttribute()
    {
        return ($this->posts->count() - 1);
    }

    public function getOldAttribute()
    {
        $cutoff = config('forum.preferences.thread.cutoff_age');
        return (!$cutoff || $this->updated_at->timestamp < strtotime($cutoff));
    }

    // Current user: reader attributes

    public function getReaderAttribute()
    {
        if (auth()->check()) {
            $reader = $this->readers()->where('user_id', auth()->user()->id)->first();

            return (!is_null($reader)) ? $reader->pivot : null;
        }

        return null;
    }

    public function getUserReadStatusAttribute()
    {
        if (!$this->old && auth()->check()) {
            if (is_null($this->reader)) {
                return self::STATUS_UNREAD;
            }

            return ($this->updatedSince($this->reader)) ? self::STATUS_UPDATED : false;
        }

        return false;
    }

    public function getNewForReaderAttribute()
    {
        $threads = $this->recent();

        // If the user is logged in, filter the threads according to read status
        if (auth()->check()) {
            $threads = $threads->filter(function ($thread)
            {
                return $thread->userReadStatus;
            });
        }

        // Filter the threads according to the user's permissions
        $threads = $threads->filter(function ($thread)
        {
            return $thread->category->userCanView;
        });

        return $threads;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Return an array of components used to construct this model's route.
     *
     * @return array
     */
    protected function getRouteComponents()
    {
        $components = [
            'category'      => $this->category->id,
            'categorySlug'  => Str::slug($this->category->title, '-'),
            'thread'        => $this->id,
            'threadSlug'    => Str::slug($this->title, '-')
        ];

        return $components;
    }

    /**
     * Mark this thread as read for the given user ID.
     *
     * @param  int  $userID
     * @return void
     */
    public function markAsRead($userID)
    {
        if (!$this->old) {
            if (is_null($this->reader)) {
                $this->readers()->attach($userID);
            } elseif ($this->updatedSince($this->reader)) {
                $this->reader->touch();
            }
        }
    }
}
