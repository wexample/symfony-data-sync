import syncLink from '../data/entity/sync_link.json';

type EntitySchema = { name: string };

export default function getGeneratedEntitySchemas(): Record<string, EntitySchema> {
  return {
    [syncLink.name]: syncLink,
  };
}
