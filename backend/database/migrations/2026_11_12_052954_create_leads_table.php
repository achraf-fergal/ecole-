
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Tenant ownership.
            $table->unsignedBigInteger('center_id');

            // Requested course. NULL supports general inquiries.
            $table->unsignedBigInteger('course_id')->nullable();

            // Assigned staff membership.
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();

            // Conversion references.
            $table->unsignedBigInteger('converted_student_profile_id')
                ->nullable();

            $table->unsignedBigInteger('converted_by_user_id')
                ->nullable();

            // Duplicate leads remain stored and linked.
            $table->unsignedBigInteger('duplicate_of_lead_id')->nullable();

            // Visitor contact information.
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();

            $table->string('preferred_contact_method', 20)->nullable();

            $table->text('message')->nullable();

            // Request source and campaign details.
            $table->string('source', 40)->default('website');
            $table->string('source_detail', 150)->nullable();

            // CRM pipeline status.
            $table->string('status', 24)->default('new');

            $table->string('lost_reason', 255)->nullable();

            // Follow-up and privacy information.
            $table->timestamp('next_follow_up_at')->nullable();

            $table->timestamp('privacy_notice_accepted_at')->nullable();

            // Student conversion tracking.
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            // Required for tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'leads_center_id_unique'
            );

            // Center must always exist, even for general inquiries.
            $table->foreign('center_id', 'leads_center_fk')
                ->references('id')
                ->on('centers')
                ->restrictOnDelete();

            // Optional course must belong to the same center.
            $table->foreign(
                ['center_id', 'course_id'],
                'leads_center_course_fk'
            )
                ->references(['center_id', 'id'])
                ->on('courses')
                ->restrictOnDelete();

            // Assigned staff must belong to the same center.
            $table->foreign(
                ['center_id', 'assigned_to_user_id'],
                'leads_center_assignee_fk'
            )
                ->references(['center_id', 'user_id'])
                ->on('center_user')
                ->restrictOnDelete();

            // Converted student profile must belong to this center.
            $table->foreign(
                ['center_id', 'converted_student_profile_id'],
                'leads_center_converted_student_fk'
            )
                ->references(['center_id', 'id'])
                ->on('student_profiles')
                ->restrictOnDelete();

            // Conversion actor must belong to this center.
            $table->foreign(
                ['center_id', 'converted_by_user_id'],
                'leads_center_converted_by_fk'
            )
                ->references(['center_id', 'user_id'])
                ->on('center_user')
                ->restrictOnDelete();

            // Duplicate lead must reference another lead in this center.
            $table->foreign(
                ['center_id', 'duplicate_of_lead_id'],
                'leads_center_duplicate_fk'
            )
                ->references(['center_id', 'id'])
                ->on('leads')
                ->restrictOnDelete();

            // CRM pipeline dashboard.
            $table->index(
                ['center_id', 'status', 'created_at'],
                'leads_center_status_created_index'
            );

            // Filter requests by course and status.
            $table->index(
                ['center_id', 'course_id', 'status'],
                'leads_center_course_status_index'
            );

            // Filter assigned requests by status.
            $table->index(
                ['center_id', 'assigned_to_user_id', 'status'],
                'leads_center_assignee_status_index'
            );

            // Support duplicate detection by phone.
            $table->index(
                ['center_id', 'phone', 'created_at'],
                'leads_center_phone_created_index'
            );

            // Support duplicate detection by email.
            $table->index(
                ['center_id', 'email', 'created_at'],
                'leads_center_email_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
