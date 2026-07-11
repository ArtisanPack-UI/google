<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'google_configurations', function ( Blueprint $table ): void {
            $table->id();
            $table->string( 'client_id' )->nullable();
            $table->text( 'client_secret' )->nullable();
            $table->string( 'redirect_uri' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'google_configurations' );
    }
};
