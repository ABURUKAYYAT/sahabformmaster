# SahabFormMaster AI Assistant Setup

## Overview
The AI Assistant provides intelligent help and real-time analytics for teachers and administrators. It can answer questions about system usage and provide data insights.

## Features
- **System Guidance**: Step-by-step help for using SahabFormMaster features
- **Real-time Analytics**: Live data analysis for attendance, fees, performance, etc.
- **Role-based Responses**: Different guidance for teachers vs administrators
- **Quick Actions**: Pre-defined common questions

## Setup Instructions

### 1. Get OpenAI API Key
1. Visit [OpenAI Platform](https://platform.openai.com/)
2. Sign up or log in to your account
3. Navigate to API Keys section
4. Create a new API key
5. Copy the API key (keep it secure!)

### 2. Configure API Key
Choose one of these methods:

#### Method A: Environment Variable (Recommended)
Set the environment variable on your server:
```bash
export OPENAI_API_KEY="your-api-key-here"
```

#### Method B: Direct Configuration
Edit `config/ai_config.php` and replace the empty string with your API key:
```php
'openai_api_key' => 'your-api-key-here',
```

### 3. Test the Setup
1. Log in as a teacher or admin
2. Click the green robot button (AI Assistant) in the bottom-right corner
3. Try asking: "How do I add a new student?" or "Show me attendance analytics"
4. The AI should respond with helpful information

## Usage Examples

### For Teachers:
- "How do I create a lesson plan?"
- "How do I enter student results?"
- "Show me attendance analytics for my classes"
- "How do I generate a test paper?"

### For Administrators:
- "How do I add a new teacher?"
- "What's the current fee collection status?"
- "How do I manage school calendar?"
- "Show me student performance analytics"

## Analytics Queries
The AI can provide real-time analytics for:
- Attendance rates and trends
- Fee collection status
- Student performance data
- School overview statistics
- Class-specific insights

## Security Notes
- API calls are made server-side to protect your API key
- All requests are authenticated and rate-limited
- Analytics respect user roles (teachers see only their data)

## Troubleshooting

### "AI service is not configured"
- Check that your OpenAI API key is properly set in `config/ai_config.php`
- Ensure the API key is valid and has credits

### "Unable to connect"
- Check your server's internet connection
- Verify OpenAI API status at [status.openai.com](https://status.openai.com/)

### No response from AI
- Check server error logs for API call failures
- Ensure PHP curl extension is enabled
- Verify API key permissions

## Cost Estimation
Using GPT-3.5-turbo, expect approximately:
- $0.002 per 1,000 tokens
- Average query: 200-500 tokens
- Monthly cost: $1-5 for moderate usage

## Support
For issues with the AI assistant setup, check:
1. API key configuration
2. Server permissions
3. OpenAI account status
4. Error logs in your server

The AI assistant is now live on all teacher and admin pages!
