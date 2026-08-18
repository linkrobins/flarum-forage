import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import ComposerRelated from './forum/components/ComposerRelated';
import RelatedPanel from './forum/components/RelatedPanel';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

app.initializers.add('linkrobins/forage', () => {
  /*
   * Extended by path rather than by importing the components: a runtime import
   * would pull DiscussionPage and DiscussionComposer into this bundle eagerly
   * and undo the code splitting Flarum does for both.
   */
  extend('flarum/forum/components/DiscussionPage', 'view', function (this: any, vnode: Mithril.Vnode<any, any>) {
    if (this.loading || !this.discussion || !vnode) {
      return;
    }

    // While loading, view returns a bare indicator rather than the page, so
    // there is nothing to append to and nothing worth appending.
    if (!Array.isArray(vnode.children)) {
      return;
    }

    vnode.children.push(m(RelatedPanel, { discussionId: Number(this.discussion.id()) }));
  });

  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    // Below the title field, which core adds at priority 0.
    items.add('forageRelated', m(ComposerRelated, { title: this.title }), -10);
  });
});
