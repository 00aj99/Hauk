package info.varden.hauk.dialog;

import info.varden.hauk.R;

/**
 * Selection of dialog buttons for custom dialogs.
 */
public enum DialogButtons {

    OK_CANCEL   (R.string.btn_ok, R.string.btn_cancel),
    YES_NO      (R.string.btn_yes, R.string.btn_no);

    // The dialog has one positive and one negative button.
    private final int positive;
    private final int negative;

    DialogButtons(int positive, int negative) {
        this.positive = positive;
        this.negative = negative;
    }

    public int getPositiveButton() {
        return this.positive;
    }

    public int getNegativeButton() {
        return this.negative;
    }
}
