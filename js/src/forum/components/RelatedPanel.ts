import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Component from 'flarum/common/Component';
import loadRelated from '../loadRelated';
import relatedRow from '../relatedRow';
import RelatedModal from './RelatedModal';
import type { RelatedDiscussion } from '../loadRelated';
import type Mithril from 'mithril';

export interface RelatedPanelAttrs {
  discussionId: number;
}

/** What the footer shows. The modal asks for the rest. */
const FOOTER_LIMIT = 5;

/**
 * The "related discussions" list under a discussion.
 *
 * Renders nothing at all until it has something to show — no heading, no empty
 * state, no skeleton. A thread with no relatives is the normal case on a small
 * forum, and an empty box under every one of them would read as a fault.
 */
export default class RelatedPanel extends Component<RelatedPanelAttrs> {
  discussions: RelatedDiscussion[] = [];

  /**
   * The discussion the current list belongs to.
   *
   * Flarum reuses one DiscussionPage across navigations, so the component is
   * updated rather than recreated and oninit fires once for many discussions.
   * Without this the panel would keep showing the previous thread's relatives.
   */
  loadedFor: number | null = null;

  oninit(vnode: Mithril.Vnode<RelatedPanelAttrs, this>) {
    super.oninit(vnode);

    this.load(vnode.attrs.discussionId);
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<RelatedPanelAttrs, this>) {
    super.onbeforeupdate(vnode);

    if (vnode.attrs.discussionId !== this.loadedFor) {
      this.load(vnode.attrs.discussionId);
    }

    return true;
  }

  view() {
    if (!this.discussions.length) {
      return null;
    }

    return m(
      'section',
      { className: 'ForageRelated' },
      m('h3', { className: 'ForageRelated-heading' }, app.translator.trans('linkrobins-forage.forum.related_heading')),
      m('ul', { className: 'ForageRelated-list' }, this.discussions.map(relatedRow)),
      this.more()
    );
  }

  /**
   * The rest of what five rows had to cut.
   *
   * Opens a modal rather than growing the list underneath the reader. The
   * footer's five are a glance on the way past; asking for the rest is a
   * deliberate act, and a control that quietly reflowed the page was read as a
   * link to somewhere else. It only appears when the footer was actually cut
   * short.
   */
  more(): Mithril.Children {
    if (this.discussions.length < FOOTER_LIMIT) {
      return null;
    }

    return m(
      Button,
      {
        className: 'Button ForageRelated-more',
        onclick: () => app.modal.show(RelatedModal, { discussionId: this.attrs.discussionId }),
      },
      app.translator.trans('linkrobins-forage.forum.related_more')
    );
  }

  load(discussionId: number) {
    // Set before the request, not after: an update mid-flight would otherwise
    // start the same load again.
    this.loadedFor = discussionId;
    this.discussions = [];

    if (!discussionId) {
      return;
    }

    loadRelated({ discussion: discussionId }).then((discussions) => {
      // A member who navigated on while this was in the air gets the answer for
      // the thread they left; drop it rather than render it under the new one.
      if (this.loadedFor !== discussionId) {
        return;
      }

      this.discussions = discussions;
      m.redraw();
    });
  }
}
