import type { Model } from './model';
import type { Permission } from './permission';

export type Role = Model & {
    name: string;
    guard_name: string;
    permissions?: Permission[];
};
