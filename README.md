# BeauBot - ChatGPT Assistant pour WordPress

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-green.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0-orange.svg)

Un chatbot intelligent alimenté par ChatGPT qui répond aux questions sur le contenu de votre site WordPress.

## ✨ Fonctionnalités

- **Interface moderne style Copilot** - Sidebar élégante et responsive
- **Positionnement flexible** - Gauche ou droite selon vos préférences
- **Support des images** - Upload, drag & drop, et analyse d'images via GPT-4o
- **Images éphémères** - Suppression automatique après 24h
- **Historique des conversations** - Sauvegarde et archivage
- **Contexte intelligent** - Le chatbot connaît le contenu de votre site
- **Mises à jour automatiques** - Directement depuis GitHub
- **Sécurisé** - Réservé aux utilisateurs connectés

## 📋 Prérequis

- WordPress 5.8 ou supérieur
- PHP 7.4 ou supérieur
- Une clé API OpenAI valide

## 🚀 Installation

### Méthode 1 : Téléchargement direct

1. Téléchargez la [dernière release](https://github.com/lebeaudigital/beaubot/releases/latest)
2. Décompressez dans `/wp-content/plugins/beaubot/`
3. Activez le plugin dans WordPress
4. Configurez votre clé API dans **BeauBot > Paramètres**

### Méthode 2 : Git

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/lebeaudigital/beaubot.git
```

## ⚙️ Configuration

1. Allez dans **BeauBot > Paramètres**
2. Entrez votre clé API OpenAI ([obtenir une clé](https://platform.openai.com/api-keys))
3. Choisissez le modèle (GPT-4o recommandé pour l'analyse d'images)
4. Personnalisez le prompt système selon vos besoins

## 🎨 Personnalisation

### Position de la sidebar

Les utilisateurs peuvent positionner la sidebar à gauche ou à droite. La préférence est sauvegardée individuellement.

### Prompt système

Personnalisez le comportement du chatbot via le champ "Prompt système" dans les paramètres.

## 🔄 Mises à jour

Le plugin vérifie automatiquement les nouvelles versions sur GitHub. Les mises à jour s'installent en un clic depuis l'admin WordPress.

## 📁 Structure du projet

```
beaubot/
├── beaubot.php              # Point d'entrée
├── includes/
│   ├── class-beaubot-admin.php
│   ├── class-beaubot-frontend.php
│   ├── class-beaubot-conversation.php
│   ├── class-beaubot-image.php
│   ├── class-beaubot-content-indexer.php
│   └── class-beaubot-updater.php
├── api/
│   ├── class-beaubot-api-chatgpt.php
│   └── class-beaubot-api-endpoints.php
├── assets/
│   ├── css/
│   └── js/
├── templates/
│   ├── admin/
│   └── frontend/
└── languages/
```

## 🛡️ Sécurité

- Seuls les utilisateurs connectés peuvent utiliser le chatbot
- Les images uploadées sont automatiquement supprimées après 24h
- Les clés API sont stockées de manière sécurisée
- Validation et sanitisation de toutes les entrées

## 🐛 Signaler un bug

Utilisez les [Issues GitHub](https://github.com/lebeaudigital/beaubot/issues) pour signaler un bug ou suggérer une amélioration.

## 📝 Changelog

### 1.0.0
- Version initiale
- Interface chatbot sidebar
- Support des images avec GPT-4o
- Historique et archivage des conversations
- Mises à jour automatiques via GitHub

## 📄 Licence

GPL v2 or later - voir [LICENSE](LICENSE)

## 👨‍💻 Auteur

**LeBeauDigital** - [GitHub](https://github.com/lebeaudigital)
