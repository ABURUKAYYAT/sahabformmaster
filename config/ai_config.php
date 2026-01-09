<?php
// AI Assistant Configuration for SahabFormMaster
// This file contains API keys and settings for AI services

return [
    // OpenAI Configuration
    'openai_api_key' => getenv('OPENAI_API_KEY') ?: '', // Set via environment variable or replace with your key

    // AI Model Settings
    'model' => 'gpt-3.5-turbo',
    'max_tokens' => 1500,
    'temperature' => 0.7,

    // Rate Limiting
    'max_requests_per_hour' => 100,
    'max_requests_per_user_per_hour' => 20,

    // Analytics Cache Settings
    'analytics_cache_ttl' => 300, // 5 minutes in seconds

    // System Prompts
    'system_prompts' => [
        'teacher' => 'You are an AI assistant for teachers using SahabFormMaster. Help with student management, lesson planning, results, attendance, and curriculum.',
        'admin' => 'You are an AI assistant for administrators using SahabFormMaster. Help with school management, analytics, user management, and system configuration.',
        'default' => 'You are an AI assistant for SahabFormMaster, a school management system. Provide helpful guidance about using the platform.'
    ]
];
?>
