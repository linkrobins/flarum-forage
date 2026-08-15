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
        label: app.translator.trans('linkrobins-forage.admin.settings.token_label'),
        help: app.translator.trans('linkrobins-forage.admin.settings.token_help'),
      },
      90
    );
});
