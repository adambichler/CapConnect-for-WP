# wordpress-cap

Plugin WordPress pour intégrer [Cap](https://github.com/tiagozip/cap) — un CAPTCHA proof-of-work auto-hébergé — dans les formulaires WordPress.

Aucune dépendance externe : tout repose sur les API natives WordPress.

---

## Prérequis

- PHP 8.2+
- WordPress 6.4+
- Une instance Cap auto-hébergée

---

## Installation

1. Copier le dossier `wordpress-cap/` dans `wp-content/plugins/`
2. Copier les assets du widget dans `assets/js/` et `assets/css/` (voir ci-dessous)
3. Activer le plugin depuis **Extensions** dans l'administration WordPress

### Assets

Les assets JS et CSS du widget Cap doivent être placés dans :

```
wordpress-cap/
└── assets/
    ├── js/cap-widget.js
    └── css/cap-widget.css
```

Ces fichiers sont disponibles dans le package [oliweb/laravel-cap](https://github.com/oli217/laravel-cap) sous `resources/js/` et `resources/css/`.

---

## Configuration

Accéder à **Réglages > Cap CAPTCHA** dans l'administration WordPress.

| Champ | Description |
|-------|-------------|
| Endpoint URL | URL complète de votre instance Cap, incluant le site-key (ex : `https://cap.example.com/votre-site-key/`) |
| Secret Key | Clé secrète générée dans le tableau de bord Cap |
| Token Field Name | Nom du champ hidden injecté par le widget (défaut : `cap-token`) |
| Timeout (seconds) | Délai avant abandon de la requête vers `/siteverify` (défaut : `5`) |
| Fail Open | Si coché, laisse passer la requête en cas d'erreur de communication avec Cap |

---

## Utilisation

### Shortcode

Insérer le widget Cap dans n'importe quelle page ou article :

```
[cap_widget]
```

Avec nonce CSP :

```
[cap_widget nonce="votre-nonce"]
```

Le shortcode enqueue automatiquement le JS et le CSS du widget.

### Intégrations natives

Le plugin s'intègre automatiquement aux formulaires WordPress suivants dès l'activation :

| Formulaire | Widget ajouté | Validation |
|------------|---------------|------------|
| Commentaires | `comment_form_after_fields` | `preprocess_comment` |
| Connexion | `login_form` | `wp_authenticate_user` |
| Inscription | `register_form` | `registration_errors` |
| WooCommerce checkout | `woocommerce_after_checkout_billing_form` | `woocommerce_checkout_process` |
| Gravity Forms | `gform_submit_button` | `gform_validation` |

Les intégrations WooCommerce et Gravity Forms ne sont actives que si les plugins correspondants sont installés et activés.

---

## Désinstallation

La désinstallation via l'interface WordPress supprime automatiquement toutes les options enregistrées en base de données (`cap_endpoint`, `cap_secret`, `cap_token_field`, `cap_timeout`, `cap_fail_open`).

---

## Licence

MIT
