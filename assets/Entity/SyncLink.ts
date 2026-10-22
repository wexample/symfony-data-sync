import AbstractApiEntity from '@wexample/js-api-entity/Common/AbstractApiEntity';
import schema from '../data/entity/sync_link.json';

export default class SyncLink extends AbstractApiEntity {
  static readonly entityName = 'syncLink';

  static retrieveEntitySchema() {
    return schema;
  }
}
