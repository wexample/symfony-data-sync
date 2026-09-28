import AbstractApiRepository from '@wexample/js-api-entity/Common/AbstractApiRepository';
import SyncLink from '../Entity/SyncLink.js';

export default class SyncLinkRepository extends AbstractApiRepository<SyncLink> {
  static getEntityType() {
    return SyncLink;
  }
}
