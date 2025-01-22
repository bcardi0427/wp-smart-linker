# WP Smart Linker

=== WP Smart Linker ===

Contributors: Gerald Haygood

Tags: ai, internal linking, seo, content optimization, openai, deepseek, gemini

Requires at least: 6.0
Tested up to: 6.4
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered internal linking suggestions using multiple AI providers including OpenAI, DeepSeek, and Google's Gemini.

## Description

WP Smart Linker enhances your WordPress site's SEO and user experience by automatically suggesting relevant internal links using advanced AI technology. Choose from multiple AI providers including OpenAI, DeepSeek, and Google's Gemini to get intelligent link suggestions based on your content.

## Features

* Multiple AI Provider Support:
  * OpenAI (GPT-3.5/4)
  * DeepSeek (V3/R1)
  * Google Gemini (2.0/1.5/1.0)
* Smart link suggestions while editing posts
* Configurable relevance threshold
* Post type exclusion options
* Firebase integration for improved performance
* Advanced model selection for each provider
* Detailed model information and tooltips
* Secure API key management

## AI Provider Details

**OpenAI Models:**
* GPT-3.5 Series:
  * GPT-3.5 Turbo
  * GPT-3.5 Turbo 0125
  * GPT-3.5 Turbo 1106
  * GPT-3.5 Turbo 16k
  * GPT-3.5 Turbo 16k 0613
  * GPT-3.5 Turbo Instruct
  * GPT-3.5 Turbo Instruct 0914

* GPT-4 Series:
  * GPT-4
  * GPT-4 0125 Preview
  * GPT-4 0613
  * GPT-4 1106 Preview
  * GPT-4 Turbo
  * GPT-4 Turbo 2024-04-09
  * GPT-4 Turbo Preview

* GPT-4 Optimized (4o) Series:
  * GPT-4o
  * GPT-4o 2024-05-13
  * GPT-4o 2024-08-06
  * GPT-4o 2024-11-20
  
* Audio Preview Models:
  * GPT-4o Audio Preview
  * GPT-4o Audio Preview 2024-10-01
  * GPT-4o Audio Preview 2024-12-17
  
* Mini Series:
  * GPT-4o Mini
  * GPT-4o Mini 2024-07-18
  * GPT-4o Mini Audio Preview
  * GPT-4o Mini Audio Preview 2024-10-01
  * GPT-4o Mini Audio Preview 2024-12-17
  
* Realtime Preview Models:
  * GPT-4o Mini Realtime Preview
  * GPT-4o Mini Realtime Preview 2024-12-17
  * GPT-4o Realtime Preview
  * GPT-4o Realtime Preview 2024-10-01
  * GPT-4o Realtime Preview 2024-12-17

**DeepSeek Models:**
* DeepSeek V3 Chat
* DeepSeek R1 Reasoner

**Gemini Models:**
* Gemini 2.0 Flash (Experimental) - Latest multimodal model with superior speed
* Gemini 1.5 Flash - Fast, versatile model for high-volume applications
* Gemini 1.5 Pro - Advanced reasoning capabilities
* Gemini 1.0 Pro - Legacy model (Deprecated)
* Gemini Pro Vision - Multimodal model for text, images, and video
* Text Embedding - For measuring text relatedness
* AQA - Source-grounded question answering

## Installation

1. Upload the plugin files to `/wp-content/plugins/wp-smart-linker`, or Download Zip file and install through WordPress's plugin installer
2. Activate the plugin
3. Go to Settings > Smart Linker
4. Choose your preferred AI provider
5. Enter your API key for the selected provider
6. Configure additional settings like threshold and post types
7. Optional: Configure Firebase integration for improved performance

## Configuration

### AI Provider Settings
1. Select your preferred AI provider (OpenAI, DeepSeek, or Gemini)
2. Enter the API key for your chosen provider
3. Select the specific model you want to use
4. Configure provider-specific settings if available

### Advanced Settings
* Suggestion Threshold: Set minimum relevance score (0.1-1.0)
* Maximum Links: Set maximum suggestions per post (1-20)
* Excluded Post Types: Choose which post types to exclude

### Firebase Integration
* Enter Firebase credentials for improved performance
* Test connection
* Sync existing posts for faster suggestions

## Frequently Asked Questions

= Which AI provider should I choose? =

* OpenAI: Best for general purpose linking and natural language understanding
* DeepSeek: Good alternative with competitive pricing
* Gemini: Excellent for multilingual content and technical topics

= How do I get API keys? =

* OpenAI: Visit https://platform.openai.com/api-keys
* DeepSeek: Visit DeepSeek's developer portal
* Gemini: Visit https://makersuite.google.com/app/apikey

= What are the system requirements? =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* Active API key for chosen provider
* Optional: Firebase account for enhanced performance

## Changelog

= 1.1.0 =
* Added support for multiple AI providers (DeepSeek and Gemini)
* Enhanced model selection interface with tooltips
* Improved settings organization
* Added comprehensive model information
* Added jQuery UI integration for better UX
* Fixed various bugs and improved performance

= 1.0.0 =
* Initial release with OpenAI integration
* Basic linking suggestions
* Firebase integration option

== Upgrade Notice ==

= 1.1.0 =
Major update adding support for DeepSeek and Gemini AI providers, plus improved interface and model selection.

== Credits ==

Developed by Gerald Haygood

Project Link: https://github.com/bcardi0427/wp-smart-linker
