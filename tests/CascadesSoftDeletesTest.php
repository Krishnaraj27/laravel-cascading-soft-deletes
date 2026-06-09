<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Tests;

use Krishnaraj\LaravelCascadingSoftDeletes\Exceptions\InvalidRelationshipException;
use Krishnaraj\LaravelCascadingSoftDeletes\Exceptions\NestingLimitExceededException;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Comment;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Post;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Profile;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Role;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CascadesSoftDeletesTest extends TestCase
{
    public function test_it_cascades_soft_deletes_to_child_relationships(): void
    {
        $user = User::create(['name' => 'John Doe']);
        
        $profile = Profile::create(['bio' => 'Developer', 'user_id' => $user->id]);
        
        $post1 = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $post2 = Post::create(['title' => 'Post 2', 'user_id' => $user->id]);

        $comment1 = Comment::create(['body' => 'Comment 1', 'post_id' => $post1->id]);
        $comment2 = Comment::create(['body' => 'Comment 2', 'post_id' => $post1->id]);
        $comment3 = Comment::create(['body' => 'Comment 3', 'post_id' => $post2->id]);

        $user->delete();

        // Check if root model is soft deleted
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        // Check if HasOne relationship was soft deleted
        $this->assertSoftDeleted('profiles', ['id' => $profile->id]);

        // Check if HasMany relationship (Post) was soft deleted
        $this->assertSoftDeleted('posts', ['id' => $post1->id]);
        $this->assertSoftDeleted('posts', ['id' => $post2->id]);

        // Check if nested HasMany relationship (Comment) was soft deleted
        $this->assertSoftDeleted('comments', ['id' => $comment1->id]);
        $this->assertSoftDeleted('comments', ['id' => $comment2->id]);
        $this->assertSoftDeleted('comments', ['id' => $comment3->id]);
    }

    public function test_it_cascades_restores_to_child_relationships(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $profile = Profile::create(['bio' => 'Developer', 'user_id' => $user->id]);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $comment = Comment::create(['body' => 'Comment 1', 'post_id' => $post->id]);

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('profiles', ['id' => $profile->id]);
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);

        // Restore parent
        $user->restore();

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertNotSoftDeleted('profiles', ['id' => $profile->id]);
        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
        $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_it_does_not_restore_relations_deleted_prior_to_parent_soft_delete(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post1 = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $post2 = Post::create(['title' => 'Post 2', 'user_id' => $user->id]);

        // Delete post2 before user is deleted
        $post2->delete();
        $this->assertSoftDeleted('posts', ['id' => $post2->id]);

        // Sleep to ensure distinct timestamps if needed (SQLite uses second resolution)
        sleep(1);

        $user->delete();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('posts', ['id' => $post1->id]);

        // Restore user
        $user->restore();

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertNotSoftDeleted('posts', ['id' => $post1->id]);
        
        // Post 2 should still be soft deleted since it was deleted independently
        $this->assertSoftDeleted('posts', ['id' => $post2->id]);
    }

    public function test_it_detaches_pivot_relationships_on_delete(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach([$role1->id, $role2->id]);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role1->id,
        ]);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role2->id,
        ]);

        $user->delete();

        // Pivot table entries should be detached (deleted)
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $user->id,
        ]);
    }

    public function test_it_cascades_force_deletes(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $comment = Comment::create(['body' => 'Comment 1', 'post_id' => $post->id]);

        $user->forceDelete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_it_throws_exception_if_nesting_limit_exceeded(): void
    {
        $user = User::create(['name' => 'John Doe']);
        
        // Temporarily exceed nesting limit by setting a long relation chain
        $user->cascadeRelationships = ['posts.comments.replies.likes'];

        $this->expectException(NestingLimitExceededException::class);
        $this->expectExceptionMessage("Relationship path 'posts.comments.replies.likes' exceeds the maximum nesting limit of 3 levels.");

        $user->delete();
    }

    public function test_it_allows_customizing_nesting_limit(): void
    {
        $user = User::create(['name' => 'John Doe']);
        
        // Define relationship of nesting depth 2
        $user->cascadeRelationships = ['posts.comments'];
        
        // Set custom nesting limit to 1
        $user->cascadeNestingLimit = 1;

        $this->expectException(NestingLimitExceededException::class);
        $user->delete();
    }

    public function test_it_throws_exception_if_relationship_does_not_exist(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $user->cascadeRelationships = ['nonExistentRelation'];

        $this->expectException(InvalidRelationshipException::class);
        $this->expectExceptionMessage("Relationship method 'nonExistentRelation' does not exist on model " . get_class($user) . ".");

        $user->delete();
    }

    public function test_it_throws_exception_if_method_does_not_return_relation(): void
    {
        $user = User::create(['name' => 'John Doe']);
        
        // We define a method on the User model that exists but doesn't return a Relation.
        // For testing, let's use 'getKey' which exists and returns a scalar/string, not Relation.
        $user->cascadeRelationships = ['getKey'];

        $this->expectException(InvalidRelationshipException::class);
        $this->expectExceptionMessage("Method 'getKey' on model " . get_class($user) . " does not return a valid Eloquent relation.");

        $user->delete();
    }

    public function test_it_cascades_nested_relationship_defined_only_on_parent(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $user->cascadeRelationships = ['posts.comments'];

        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $post->cascadeRelationships = []; // Disable post's own cascade

        $comment = Comment::create(['body' => 'Comment 1', 'post_id' => $post->id]);

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_it_can_suppress_errors_and_log_them(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $user->cascadeRelationships = ['nonExistentRelation'];
        
        // Suppress errors dynamically on model
        $user->cascadeThrowOnError = false;

        \Illuminate\Support\Facades\Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, "Cascading soft delete/restore error on model");
            });

        // This should not throw exception
        $user->delete();
    }

    public function test_it_rolls_back_transaction_on_error_if_configured(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        
        // posts will succeed, but nonExistentRelation will throw error
        $user->cascadeRelationships = ['posts', 'nonExistentRelation'];
        $user->cascadeUseTransaction = true;
        $user->cascadeRollbackOnError = true;
        $user->cascadeThrowOnError = true;

        try {
            $user->delete();
        } catch (\Throwable $e) {
            // expected exception
        }

        // Since transaction rolled back, the post should NOT be soft deleted!
        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
    }

    public function test_it_commits_successful_deletes_when_rollback_is_disabled(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        
        // posts will succeed, but nonExistentRelation will throw error
        $user->cascadeRelationships = ['posts', 'nonExistentRelation'];
        $user->cascadeUseTransaction = true;
        $user->cascadeRollbackOnError = false;
        $user->cascadeThrowOnError = true;

        try {
            $user->delete();
        } catch (\Throwable $e) {
            // expected exception
        }

        // Since transaction did NOT roll back, the post should stay soft deleted!
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
    }
}
