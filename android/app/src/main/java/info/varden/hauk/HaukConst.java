package info.varden.hauk;

/**
 * Constants used in the Hauk app.
 *
 * @author Marius Lindvall
 */
public final class HaukConst {
    // Duration units.
    public static final int DURATION_UNIT_MINUTES = 0;
    public static final int DURATION_UNIT_HOURS = 1;
    public static final int DURATION_UNIT_DAYS = 2;

    // Share creation modes.
    public static final int SHARE_MODE_CREATE_ALONE = 0;
    public static final int SHARE_MODE_CREATE_GROUP = 1;
    public static final int SHARE_MODE_JOIN_GROUP = 2;

    // Minimum backend version supporting group shares.
    public static final Version VERSION_COMPAT_GROUP_SHARE = new Version("1.1");
}
