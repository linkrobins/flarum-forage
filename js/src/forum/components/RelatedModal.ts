import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import loadRelated from '../loadRelated';
import relatedRow from '../relatedRow';
import type { RelatedDiscussion } from '../loadRelated';
import type Mithril from 'mithril';

export interface RelatedModalAttrs {
  discussionId: number;
}

/** What the footer shows, and what this asks for. Both bounded server side. */
const EXPANDED_LIMIT = 15;

/**
 * The rest of what the footer had to cut.
 *
 * A modal rather than a longer list in place: the footer's five are a glance on
 * the way past, and asking for the rest is a deliberate act that deserves its
 * own surface. It is also honest about being one, which a link that quietly
 * grew the list underneath you was not.
 */
export default class RelatedModal extends Modal<RelatedModalAttrs & any> {
  discussions: RelatedDiscussion[] = [];

  loadingList = true;

  oninit(vnode: Mithril.Vnode<RelatedModalAttrs & any, this>) {
    super.oninit(vnode);

    loadRelated({ discussion: this.attrs.discussionId, limit: EXPANDED_LIMIT }).then((discussions) => {
      this.discussions = discussions;
      this.loadingList = false;
      m.redraw();
    });
  }

  className(): string {
    return 'ForageRelatedModal Modal--medium';
  }

  title(): Mithril.Children {
    return app.translator.trans('linkrobins-forage.forum.related_heading');
  }

  content(): Mithril.Children {
    return m(
      'div',
      { className: 'Modal-body' },
      this.loadingList
        ? m(LoadingIndicator, { display: 'block' })
        : this.discussions.length
          ? m('ul', { className: 'ForageRelated-list' }, this.discussions.map(relatedRow))
          : m('p', { className: 'helpText' }, app.translator.trans('linkrobins-forage.forum.related_none'))
    );
  }
}
