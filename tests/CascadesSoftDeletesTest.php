<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Tests;

use Krishnaraj\LaravelCascadingSoftDeletes\Exceptions\InvalidRelationshipException;
use Krishnaraj\LaravelCascadingSoftDeletes\Exceptions\NestingLimitExceededException;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Comment;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Post;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Profile;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Role;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Team;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\User;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Company;
use Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models\Employee;
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

    public function test_it_creates_tracking_records_on_cascade_delete(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $comment = Comment::create(['body' => 'Comment 1', 'post_id' => $post->id]);

        $user->delete();

        // Verify tracking records were created for each cascade level
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);

        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => Post::class,
            'parent_id' => $post->id,
            'child_type' => Comment::class,
            'child_id' => $comment->id,
        ]);
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

    public function test_it_cleans_up_tracking_records_after_restore(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

        $user->delete();

        // Tracking record should exist after delete
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);

        $user->restore();

        // Tracking record should be cleaned up after restore
        $this->assertDatabaseMissing('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);
    }

    public function test_it_does_not_restore_independently_deleted_children(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post1 = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $post2 = Post::create(['title' => 'Post 2', 'user_id' => $user->id]);

        // Delete post2 independently (not through cascade)
        $post2->delete();
        $this->assertSoftDeleted('posts', ['id' => $post2->id]);

        // No tracking record should exist for the independent deletion
        $this->assertDatabaseMissing('cascade_deletions', [
            'child_type' => Post::class,
            'child_id' => $post2->id,
        ]);

        // Now cascade-delete user (which will cascade to post1 only,
        // since post2 is already trashed)
        $user->delete();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('posts', ['id' => $post1->id]);

        // Only post1 should have a tracking record
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post1->id,
        ]);
        $this->assertDatabaseMissing('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post2->id,
        ]);

        // Restore user
        $user->restore();

        // post1 should be restored (was cascade-deleted)
        $this->assertNotSoftDeleted('posts', ['id' => $post1->id]);

        // post2 should remain soft-deleted (was independently deleted)
        $this->assertSoftDeleted('posts', ['id' => $post2->id]);
    }

    public function test_it_does_not_restore_child_when_another_parent_is_still_deleted(): void
    {
        // Create two parents that both cascade to the same child
        $user = User::create(['name' => 'John Doe']);
        $team = Team::create(['name' => 'Team Alpha']);

        // Post belongs to both User and Team
        $post = Post::create([
            'title' => 'Shared Post',
            'user_id' => $user->id,
            'team_id' => $team->id,
        ]);

        // Delete User first → cascades to Post
        $user->delete();
        $this->assertSoftDeleted('posts', ['id' => $post->id]);

        // Delete Team → Post is already trashed, so no new tracking entry
        $team->delete();

        // Only User's cascade should have a tracking entry for the Post
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);

        // Restore User → Post should be restored because Team's cascade
        // didn't create a tracking entry (Post was already trashed)
        $user->restore();
        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
    }

    public function test_multi_parent_cascade_prevents_restore_when_both_cascaded(): void
    {
        // Create two parents that both cascade to the same child
        $user = User::create(['name' => 'John Doe']);
        $team = Team::create(['name' => 'Team Alpha']);

        $post = Post::create([
            'title' => 'Shared Post',
            'user_id' => $user->id,
            'team_id' => $team->id,
        ]);

        // Delete Team first → cascades to Post, creates tracking entry
        $team->delete();
        $this->assertSoftDeleted('posts', ['id' => $post->id]);

        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => Team::class,
            'parent_id' => $team->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);

        // Restore Team → Post should be restored (only Team's cascade
        // created a tracking entry)
        $team->restore();
        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
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

    public function test_force_delete_cleans_up_tracking_records(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

        // Soft delete first to create tracking records
        $user->delete();
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
        ]);

        // Force delete should clean up all tracking records
        $user->forceDelete();
        $this->assertDatabaseMissing('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
        ]);
    }

    public function test_independent_restore_cleans_up_tracking_records(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

        $user->delete();

        // Tracking record exists
        $this->assertDatabaseHas('cascade_deletions', [
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);

        // Independently restore the post (not through cascade)
        $post->restore();

        // Tracking records for the post as a child should be cleaned up
        $this->assertDatabaseMissing('cascade_deletions', [
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);
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

    public function test_it_does_not_create_tracking_for_already_trashed_children(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post1 = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $post2 = Post::create(['title' => 'Post 2', 'user_id' => $user->id]);

        // Independently delete post2 first
        $post2->delete();

        // Now cascade-delete user
        $user->delete();

        // Only post1 should have a tracking record (it was not already trashed)
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post1->id,
        ]);

        // post2 should NOT have a tracking record (it was already trashed)
        $this->assertDatabaseMissing('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post2->id,
        ]);
    }

    public function test_nested_cascade_restore_works_with_tracking(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);
        $comment1 = Comment::create(['body' => 'Comment 1', 'post_id' => $post->id]);
        $comment2 = Comment::create(['body' => 'Comment 2', 'post_id' => $post->id]);

        $user->delete();

        // All should be soft-deleted
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        $this->assertSoftDeleted('comments', ['id' => $comment1->id]);
        $this->assertSoftDeleted('comments', ['id' => $comment2->id]);

        // Verify nested tracking records exist
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => Post::class,
            'parent_id' => $post->id,
            'child_type' => Comment::class,
            'child_id' => $comment1->id,
        ]);

        $user->restore();

        // All should be restored
        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
        $this->assertNotSoftDeleted('comments', ['id' => $comment1->id]);
        $this->assertNotSoftDeleted('comments', ['id' => $comment2->id]);

        // All tracking records should be cleaned up
        $this->assertEquals(0, DB::table('cascade_deletions')->count());
    }

    public function test_stale_tracking_records_are_cleaned_during_restore(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

        // Cascade-delete user → creates tracking for post
        $user->delete();

        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);

        // Force-delete the post independently (simulating cleanup outside cascade)
        $post->forceDelete();

        // Restore user → the tracking entry for post is now stale
        $user->restore();

        // Stale tracking record should be cleaned up
        $this->assertDatabaseMissing('cascade_deletions', [
            'parent_type' => User::class,
            'parent_id' => $user->id,
            'child_type' => Post::class,
            'child_id' => $post->id,
        ]);
    }

    public function test_it_cascades_soft_deletes_and_restores_with_string_keys(): void
    {
        $companyId = 'company-uuid-1234';
        $employeeId1 = 'employee-uuid-5678';
        $employeeId2 = 'employee-uuid-9012';

        $company = Company::create([
            'id' => $companyId,
            'name' => 'Acme Corp',
        ]);

        $employee1 = Employee::create([
            'id' => $employeeId1,
            'company_id' => $companyId,
            'name' => 'Alice',
        ]);

        $employee2 = Employee::create([
            'id' => $employeeId2,
            'company_id' => $companyId,
            'name' => 'Bob',
        ]);

        // Soft delete the company (parent)
        $company->delete();

        // Check company is soft deleted
        $this->assertSoftDeleted('companies', ['id' => $companyId]);

        // Check employees (children) are soft deleted
        $this->assertSoftDeleted('employees', ['id' => $employeeId1]);
        $this->assertSoftDeleted('employees', ['id' => $employeeId2]);

        // Check tracking records were created with string keys
        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => Company::class,
            'parent_id' => $companyId,
            'child_type' => Employee::class,
            'child_id' => $employeeId1,
        ]);

        $this->assertDatabaseHas('cascade_deletions', [
            'parent_type' => Company::class,
            'parent_id' => $companyId,
            'child_type' => Employee::class,
            'child_id' => $employeeId2,
        ]);

        // Restore the company
        $company->restore();

        // Check they are restored
        $this->assertNotSoftDeleted('companies', ['id' => $companyId]);
        $this->assertNotSoftDeleted('employees', ['id' => $employeeId1]);
        $this->assertNotSoftDeleted('employees', ['id' => $employeeId2]);

        // Tracking records should be cleaned up
        $this->assertDatabaseMissing('cascade_deletions', [
            'parent_type' => Company::class,
            'parent_id' => $companyId,
        ]);
    }

    public function test_it_respects_config_to_disable_cascade_restores(): void
    {
        config(['cascading-soft-deletes.cascade_on_restore' => false]);

        $user = User::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('posts', ['id' => $post->id]);

        $user->restore();

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        // Child post should NOT be restored
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
    }

    public function test_it_respects_model_property_to_disable_cascade_restores(): void
    {
        $user = UserWithNoCascadeRestore::create(['name' => 'John Doe']);
        $post = Post::create(['title' => 'Post 1', 'user_id' => $user->id]);

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('posts', ['id' => $post->id]);

        $user->restore();

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        // Child post should NOT be restored
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
    }

    public function test_it_respects_config_to_disable_belongs_to_many_detach(): void
    {
        config(['cascading-soft-deletes.detach_belongs_to_many' => false]);

        $user = User::create(['name' => 'John Doe']);
        $role = Role::create(['name' => 'Admin']);
        $user->roles()->attach($role->id);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $user->delete();

        // The pivot record should NOT be detached
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_it_respects_model_property_to_disable_belongs_to_many_detach(): void
    {
        $user = UserWithNoBelongsToManyDetach::create(['name' => 'John Doe']);
        $role = Role::create(['name' => 'Admin']);
        $user->roles()->attach($role->id);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $user->delete();

        // The pivot record should NOT be detached
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }
}

class UserWithNoCascadeRestore extends User
{
    protected $table = 'users';
    protected $cascadeOnRestore = false;
}

class UserWithNoBelongsToManyDetach extends User
{
    protected $table = 'users';
    protected $cascadeDetachBelongsToMany = false;
}


