=== BeauBot - ChatGPT Assistant ===
Contributors: beaubot
Tags: chatbot, chatgpt, openai, ai, assistant, support
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Un chatbot intelligent alimenté par ChatGPT qui répond aux questions sur le contenu de votre site WordPress.

== Description ==

BeauBot est une extension WordPress qui intègre un chatbot intelligent basé sur ChatGPT directement dans votre site. Le chatbot peut répondre aux questions de vos utilisateurs en se basant sur le contenu de vos pages et articles.

= Fonctionnalités principales =

* **Interface moderne style Copilot** - Une sidebar élégante et responsive
* **Positionnement flexible** - Placez la sidebar à gauche ou à droite selon vos préférences
* **Support des images** - Les utilisateurs peuvent envoyer des images pour analyse
* **Images éphémères** - Les images uploadées sont automatiquement supprimées après 24h
* **Historique des conversations** - Gardez une trace de toutes les conversations
* **Archivage** - Archivez les conversations importantes
* **Contexte intelligent** - Le chatbot connaît le contenu de votre site
* **Sécurisé** - Seuls les utilisateurs connectés peuvent utiliser le chatbot

= Configuration requise =

* WordPress 5.8 ou supérieur
* PHP 7.4 ou supérieur
* Une clé API OpenAI valide

== Installation ==

1. Téléchargez le plugin et décompressez-le dans `/wp-content/plugins/beaubot/`
2. Activez le plugin dans le menu 'Extensions' de WordPress
3. Allez dans BeauBot > Paramètres
4. Entrez votre clé API OpenAI
5. Configurez les options selon vos besoins

== Frequently Asked Questions ==

= Comment obtenir une clé API OpenAI ? =

1. Créez un compte sur [platform.openai.com](https://platform.openai.com)
2. Allez dans API Keys
3. Créez une nouvelle clé API
4. Copiez la clé et collez-la dans les paramètres BeauBot

= Le chatbot est-il visible par tous les visiteurs ? =

Non, seuls les utilisateurs connectés à votre site WordPress peuvent voir et utiliser le chatbot.

= Les images sont-elles stockées définitivement ? =

Non, les images uploadées dans le chat sont automatiquement supprimées après 24 heures pour des raisons de confidentialité et d'espace disque.

= Puis-je personnaliser le comportement du chatbot ? =

Oui, vous pouvez modifier le "prompt système" dans les paramètres pour personnaliser la personnalité et le comportement du chatbot.

= Quel modèle ChatGPT utiliser ? =

Nous recommandons GPT-4o car il supporte l'analyse d'images. GPT-4o Mini est une alternative économique qui supporte également les images.

== Screenshots ==

1. Interface du chatbot en sidebar
2. Page de paramètres admin
3. Historique des conversations
4. Upload d'images

== Changelog ==

= 1.0.0 =
* Version initiale
* Interface chatbot style sidebar
* Support des images avec analyse GPT-4o
* Historique et archivage des conversations
* Nettoyage automatique des images (24h)
* Contexte basé sur le contenu du site

== Upgrade Notice ==

= 1.0.0 =
Première version de BeauBot.
