import type { Model } from "./model";

export type Setting = Model & {
    key: string;
    value: string;
};