import app from 'flarum/admin/app';
import ForageStatus from './admin/components/ForageStatus';

app.initializers.add('linkrobins/forage', () => {
  app.registry
    .for('linkrobins-forage')
    // Above the field, because the first thing an admin wants to know on this
    // page is whether the key they pasted is working.
    .registerSetting(() => m(ForageStatus), 100, 'status')
    .registerSetting(
      {
        setting: 'linkrobins-forage.token',
        type: 'text',
        label: app.translator.trans('linkrobins-forage.admin.key_label'),
        help: app.translator.trans('linkrobins-forage.admin.key_help'),
      },
      90
    )
    /*
     * Both default to on, which is what an unset setting means to the backend.
     * Flarum renders an unsaved boolean as unchecked, so each carries a default
     * of true rather than letting the box disagree with the forum.
     */
    .registerSetting(
      {
        setting: 'linkrobins-forage.related_discussion',
        type: 'boolean',
        default: true,
        label: app.translator.trans('linkrobins-forage.admin.related_discussion_label'),
        help: app.translator.trans('linkrobins-forage.admin.related_discussion_help'),
      },
      80
    )
    .registerSetting(
      {
        setting: 'linkrobins-forage.related_composer',
        type: 'boolean',
        default: true,
        label: app.translator.trans('linkrobins-forage.admin.related_composer_label'),
        help: app.translator.trans('linkrobins-forage.admin.related_composer_help'),
      },
      70
    );
});
