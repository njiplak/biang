import { Trash } from "lucide-react"
import { Button } from "@/components/ui/button"
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog"


type DeleteDialogProps = {
    id: any,
    onDelete: (params: any) => void,
    onOpenChange: (id: any) => void
}

export const DeleteDialog = ({ id, onDelete, onOpenChange }: DeleteDialogProps) => {
    return (
        <AlertDialog open={id} onOpenChange={() => onOpenChange(null)} >
            <AlertDialogContent>
                <AlertDialogHeader>
                <AlertDialogTitle>Confirmation</AlertDialogTitle>
                <AlertDialogDescription>
                    You want to delete data, note this action is irreversible.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                {
                    id && <Button variant="destructive" onClick={onDelete} type="button">
                            <Trash />
                            Delete
                        </Button>
                }
            </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    )
}