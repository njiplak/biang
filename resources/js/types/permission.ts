import type { Model } from './model';

export type Permission = Model & {
    name: string;
    guard_name: string;
};
